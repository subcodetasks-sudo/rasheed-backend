# Daily Journal — Equations, Validation & Business Rules

You are implementing or reviewing the Daily Journal calculation engine for Rashid Financial (Laravel API). Follow these rules exactly. All money values use `decimal(15,2)` and `round(..., 2)`. Null income/expense/contribution are treated as `0` in math (nulls may still be stored for editable fields).

---

## Editable vs calculated fields

**User may write (API input):**
- `daily_income` (≥ 0, nullable)
- `daily_expense` (≥ 0, nullable)
- `contribution` (≥ 0, nullable)

**System-calculated (reject if client sends them):**
- `administrative_expense`
- `administrative_fee`
- `operational_deduction`
- `daily_total`
- `fund_balance`
- `administrative_debt`
- `accumulated_administrative_debt`

Unique key per row: `(project_id, journal_date)`. Only **Active** projects are allowed.

---

## Core equations

### 1. Administrative fee (from Project + Settings)
```
if project.administrative_exempt → 0
else → round(daily_income × (effective_admin_fee_percentage / 100), 2)
```

Percentage = **effective** `admin_fee_percentage` for the journal date (not the live settings value,
and not the project's stored snapshot used only for display / create-time copy).

Settings changes are scheduled for the **next calendar day** via `administrative_fee_rates`.
Each change also pins a row for the current day holding the percentage that was already in force, so the new
value cannot reach today even if the open-ended history row is later re-seeded.
Resolve: latest rate where `effective_from <= journal_date` (default **12.00** if none).
A mid-day settings update never affects today or earlier dates; recalculation of a historical journal uses the percentage that was effective on that date.

### 2. Operational deduction (from Project + Settings)
Pool = **effective** `total_operational_deduction` for the journal date (not the live settings value).

Settings changes are scheduled for the **next calendar day** via `operational_deduction_rates`.
Each change also pins a row for the current day holding the pool that was already in force, so the new
amount cannot reach today even if the open-ended history row is later re-seeded.
Resolve: latest rate where `effective_from <= journal_date` (default **1081.00** if none).
A mid-day settings update never affects today or earlier dates; recalculation of a historical journal uses the pool that was effective on that date.

Participating projects = Active AND not `Exempt`.
Relative base = sum of incomes of projects with type `Relative` only.

| Type | Formula |
|------|---------|
| `relative` | `totalParticipatingIncome > 0 ? round((projectIncome / totalParticipatingIncome) × pool, 2) : 0` |
| `fixed` | `project.operational_fixed_amount` |
| `exempt` | `0` |

### 3. Administrative expense (from Inventory)
```
administrative_expense = round(
  SUM(inventory_movements.total_cost)
  WHERE type = outgoing
    AND expense_type = administrative
    AND beneficiary_project_id = project_id
    AND movement_date = journal_date
, 2)
```
- Use persisted FIFO `total_cost` only (never recompute FIFO here).
- Operational outgoing stock does **not** affect this field.
- Charged to the **beneficiary** project, not the inventory owner.

### 4. Daily total
```
daily_total = round(
  income + contribution
  − expense
  − administrative_expense
  − administrative_fee
  − operational_deduction
, 2)
```

### 5. Fund balance (signed)
```
fund_balance = round(previous_day_fund_balance + daily_total, 2)
```
Previous = latest entry with `journal_date < today` for that project, or `0`.

The balance is **signed**: it may be positive, zero, or negative. A negative fund balance is carried forward as-is to the next journal date. It is **not** clamped to zero. A negative balance alone does **not** create Administrative Debt.

### 6. Administrative Debt
Administrative Debt has three sources. Cases 1 and 2 consume that day’s Administrative Fund (`administrative_fee`). Contribution is a separate additive source (allowed only when Pass-1 Fund Balance is in deficit). Allocation for Cases 1/2 is **expense-first**; the same fee cannot be spent twice. Debt calculation does **not** change Daily Total or Fund Balance — contribution already reduces the fund deficit via `daily_total`.

```
balance_before_contribution = round(fund_balance − contribution, 2)

expense_consumed = min(administrative_expense, administrative_fee)
remaining_fee = round(administrative_fee − expense_consumed, 2)

expense_debt = administrative_expense > administrative_fee
    ? expense_consumed
    : 0

deficit_debt = balance_before_contribution < 0
    ? min(|balance_before_contribution|, remaining_fee)
    : 0

fund_consumption_debt = round(expense_debt + deficit_debt, 2)
administrative_debt = round(fund_consumption_debt + contribution, 2)
```

**Case 1 — deficit cover:** when pre-contribution `fund_balance < 0`, debt equals the fee remaining after expense allocation (`min(|balance|, remaining_fee)`). If fee is 0 (e.g. exempt) or fully reserved by expense at/below fee, Case 1 debt is 0.

**Case 2 — expense exceeds fee:** when `administrative_expense > administrative_fee`, debt equals the fee amount consumed (`expense_consumed` = the full fee). When expense ≤ fee, Case 2 debt is 0; expense still reduces `remaining_fee` for Case 1.

**Contribution — additive debt + deficit reduction:** requires Pass-1 fund deficit (super-admin + amount ≤ remaining deficit). Raises `daily_total` / signed fund (reduces deficit) **and** adds the contribution amount to today’s Administrative Debt. Re-saving a different contribution recomputes from the same fund-consumption base (never compounds: base 100 + 30 → 130; re-save 40 → 140, not 170).

**Not a debt source alone:** a negative fund balance with no available fee and no contribution.

### 7. Accumulated debt
```
accumulated_administrative_debt = round(
  previous_accumulated_administrative_debt + today’s_administrative_debt
, 2)
```

### 8. Explicit surplus repayment (dedicated endpoint only)
Priority (stop when surplus exhausted):
1. If `fund_balance ≤ 0` → reject / no-op.
2. Repay today’s `administrative_debt` first; also reduce `accumulated_administrative_debt` by the same amount.
3. Repay remaining `accumulated_administrative_debt`.

```
surplus = fund_balance
repay_today = min(surplus, administrative_debt)
administrative_debt -= repay_today; surplus -= repay_today; accumulated -= repay_today

repay_acc = min(surplus, accumulated)
accumulated -= repay_acc; surplus -= repay_acc

fund_balance = surplus
```

**Critical:** Journal save NEVER auto-repays prior accumulated debt. Surplus stays in `fund_balance` until `POST /api/v1/daily-journals/repay-debt`.

---

## Recalculation pipeline (strict order)

1. Ensure rows for all active projects on that date
2. Calculate administrative fees
3. Calculate operational deductions
4. Resolve administrative expense (inventory)
5. Calculate daily totals
6. Calculate fund balances (using previous day; signed carry-forward)
7. Capture `remainingDeficits = |fund_balance| if < 0 else 0` (for contribution validation)
8. Calculate administrative debt (fund consumption + contribution)
9. Update accumulated administrative debt
10. Persist calculated fields

---

## Two-pass write (when saving contributions)

**Pass 1**
- Upsert editable fields with positive contributions forced to `null`
- Full recalculate (fees, op, inventory expense included)
- Capture `remainingDeficits`

**If no positive contribution → stop (Pass 1 result is final)**

**Pass 2**
- Validate contributions against Pass-1 deficits + role
- Upsert real contributions
- Recalculate with `preserveIncomeDerivedDeductions=true`
  (keep fee / op / admin expense from Pass 1; recompute totals → balances → debt)

- **PUT** (save): omitted income/expense/contribution → cleared to null
- **PATCH** (update): only keys present in payload are updated

---

## Contribution validation (positive contribution only)

| Rule | Error |
|------|--------|
| User is not `super-admin` | `contribution_requires_super_admin` |
| Pass-1 remaining deficit ≤ 0 | `contribution_requires_deficit` |
| contribution > remaining deficit | `contribution_exceeds_remaining_deficit` |

- Null / zero contribution: allowed for finance users; skip contribution checks.
- Remaining deficit = `|fund_balance|` after Pass-1 totals+balances (signed balance may be negative), with contribution zeroed.
- Contribution must not exceed that remaining deficit.

### Worked example
- Yesterday fund = 50; today expense = 200 → Pass-1 fund = −150 → remaining deficit = 150
- Contribute 100 → daily_total = −100, fund_balance = **−50** (deficit reduced); day debt = **100** (contribution; fee 0 on exempt project)
- Contribute exactly 150 → fund_balance **0**, day debt **150**

### Administrative Debt worked examples
- Income 200 @ 12% → fee 24; expense 0; fund negative → Case 1 debt = **24**
- Fee 50; admin expense 80; fund ≥ 0 → Case 2 debt = **50**
- Fee 50; admin expense 30; fund −100 → expense_consumed 30 (no Case 2); remaining_fee 20; Case 1 debt = **20**
- Fee 0; fund −150 → debt = **0** (negative alone does not create debt)
- Fee 100; Pass-1 fund −1100; contribution 30 → base 100 + 30 = **130**; fund −1070
- Same day re-save contribution 40 → base still 100 + 40 = **140** (not compounded)

---

## Request validation (Save / Update)

```
journal_date: sometimes|nullable|date_format:Y-m-d
entries: required|array|min:1
entries.*.project_id: required|integer|distinct|exists:projects,id
entries.*.daily_income|daily_expense|contribution: nullable|numeric|min:0
```

After-validation:
- Reject any calculated field in the payload
- Reject non-Active projects (`only_active_projects_allowed_in_journal`)

Auth: Sanctum + role `super-admin|finance`. Inventory role: forbidden.

---

## Repay-debt endpoint validation

`POST /api/v1/daily-journals/repay-debt` (roles: `super-admin|finance`)

Request:
- `journal_date`: required `Y-m-d`
- `project_id`: required, exists in `projects`

Action rejections:
| Condition | Message |
|-----------|---------|
| Project missing or not active | `repay_debt_project_not_found` |
| No entry for project+date | `repay_debt_entry_not_found` |
| `fund_balance ≤ 0` | `repay_debt_requires_surplus` |

---

## Edge cases (must hold)

| Case | Behavior |
|------|----------|
| Zero / null inputs | Treated as 0 in math |
| Positive balance | No Case 1 debt from balance |
| Negative balance, fee available | Case 1 debt = min(\|balance\|, remaining_fee after expense) |
| Negative balance, fee 0 | Debt 0 (negative alone never creates debt) |
| Admin expense > fee | Case 2 debt = fee (expense-first) |
| Admin expense ≤ fee | No Case 2 debt; remaining fee available for Case 1 |
| Contribution | Requires Pass-1 deficit; reduces fund deficit via daily_total; adds amount to debt |
| Contribution re-save | Recomputes from same fund-consumption base (never compounds) |
| Surplus day after prior debt | Surplus kept; accumulated unchanged until repay endpoint |
| Pass-2 preserve | Fee / op / admin expense frozen from Pass 1 |
| Non-active projects | Rejected on save |
| Editing one date | Does not auto-recalculate later dates |
| Project delete | Blocked if any journal entries exist |

---

## Quick formula checks

```
daily_total(1000,50,100,20,120,30) = 780
fund_balance(100,50) = 150; fund_balance(40,-100) = -60
calculateAdministrativeDebt(fund=-60, fee=0, expense=0) = 0
calculateAdministrativeDebt(fund=-100, fee=24, expense=0) = 24
calculateAdministrativeDebt(fund=10, fee=50, expense=80) = 50
calculateAdministrativeDebt(fund=-100, fee=50, expense=30) = 20
applyDebt(base_fund=-1100, fee=100, expense=0, contribution=30) = 130
applyDebt(base_fund=-1100, fee=100, expense=0, contribution=40) = 140
fund_balance(1000,-300) = 700; fund_balance(300,-900) = -600
fund_balance(-400,250) = -150; fund_balance(-400,800) = 400
accumulated(10,24) = 34
admin_fee(1000 @ 15%) = 150
repay(150, debt=0, acc=100) → (50, 0, 0)
repay(40, 0, 100) → (0, 0, 60)
repay(30, 20, 50) → (0, 0, 20)  // today first, then accumulated
```

When implementing, changing, or reviewing Daily Journal logic, preserve this exact order, these formulas, and these validations. Do not auto-repay debt on save. Do not convert negative fund balances into Administrative Debt without Administrative Fund consumption. Contribution requires a Pass-1 fund deficit, increases debt by its amount, and reduces the fund deficit via daily_total; re-saves must not compound. Do not let contributions exceed Pass-1 remaining deficit. Do not recalculate fee/op/admin expense on Pass 2.
