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
- `uncovered_administrative_expense`
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
  − administrative_fee
  − operational_deduction
, 2)
```
Administrative expense is **not** subtracted here. It is applied separately from same-day fund surplus (see §5a).

### 5. Fund balance (signed, before administrative expense coverage)
```
intermediate_fund_balance = round(previous_day_fund_balance + daily_total, 2)
```
Previous = latest entry with `journal_date < today` for that project, or `0`.

The balance is **signed**: it may be positive, zero, or negative. A negative fund balance is carried forward as-is to the next journal date. It is **not** clamped to zero. A negative balance alone does **not** create Administrative Debt.

### 5a. Administrative expense coverage (fund surplus only)

After `intermediate_fund_balance` is known, cover administrative expense from **same-day surplus only**. Administrative fee (percentage) does **not** participate.

```
surplus = max(0, intermediate_fund_balance)
covered = min(surplus, administrative_expense)
fund_balance = round(intermediate_fund_balance − covered, 2)
uncovered_administrative_expense = round(administrative_expense − covered, 2)
```

| Case | Condition | Result |
|------|-----------|--------|
| 1 | surplus ≥ expense | Full expense deducted from surplus; `uncovered = 0` |
| 2 | 0 < surplus < expense | Use all surplus; `fund_balance = 0`; remainder → `uncovered` |
| 3 | surplus = 0 | `covered = 0`; full expense → `uncovered` |

Uncovered expense is **not** fund deficit and **not** administrative debt. Per day, `SUM(uncovered_administrative_expense)` across all projects feeds Administrative Fund **`project_administration`** (إداري المشروعات).

Separately, per day `SUM(administrative_fee)` across all projects feeds Administrative Fund
**`total_administrative_percentage`** (إجمالي النسبة الإدارية). That column is display-only: it is never
added to `project_administration`, never substituted for it, and never enters the Administrative Fund's
`total_income` / `net`, so the same amount cannot be counted twice.

### 6. Administrative Debt (Case 1 — same-day admin percentage for deficit only)

Administrative Debt has two sources: **Case 1** (deficit cover from that day's full `administrative_fee`) and **Contribution**. Administrative expense is handled in §5a and never creates debt. **Case 2 (fee-for-expense) is removed.** The two procedures are independent.

```
balance_before_contribution = round(fund_balance − contribution, 2)

deficit_debt = balance_before_contribution < 0
    ? min(|balance_before_contribution|, administrative_fee)
    : 0

fund_consumption_debt = round(deficit_debt, 2)
administrative_debt = round(fund_consumption_debt + contribution, 2)
```

**Case 1 — deficit cover:** when post-coverage, pre-contribution `fund_balance < 0`, debt equals `min(|balance|, full same-day administrative_fee)`. Unused fee from prior days is **not** carried forward.

**Contribution — additive debt + deficit reduction:** requires Pass-1 fund deficit (super-admin + amount ≤ remaining deficit). Re-saving recomputes from the same fund-consumption base (never compounds).

**Not a debt source:** uncovered administrative expense; negative balance with fee 0 and no contribution.

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
6. Calculate intermediate fund balances (using previous day; signed carry-forward)
7. Apply administrative expense coverage from surplus → final `fund_balance`, `uncovered_administrative_expense`
8. Capture `remainingDeficits = |fund_balance| if < 0 else 0` (for contribution validation)
9. Calculate administrative debt (Case 1 fund consumption + contribution)
10. Update accumulated administrative debt
11. Persist calculated fields

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
  (keep fee / op / admin expense from Pass 1; recompute totals → balances → expense coverage → debt)

- **PUT** (save): omitted income/expense/contribution → cleared to null
- **PATCH** (update): only keys present in payload are updated

---

## Contribution validation

Any change to a contribution value (create, raise, lower, or clear) requires `super-admin`.

| Rule | Error |
|------|--------|
| User is not `super-admin` (any contribution mutation) | `contribution_requires_super_admin` |
| Pass-1 remaining deficit ≤ 0 (positive amount) | `contribution_requires_deficit` |
| contribution > remaining deficit | `contribution_exceeds_remaining_deficit` |
| additional debit > available Administrative Percentage Balance | `contribution_exceeds_admin_percentage_balance` |

### Administrative Percentage Balance (org pool)

```
available = SUM(administrative_fee) − SUM(admin_percentage_balance_debits.amount) + SUM(admin_percentage_balance_credits.amount)
```

- Contributions are funded only from this pool.
- Max contribution for an entry = `min(remaining_deficit, already_consumed_for_entry + available)`.
- On save, any increase above prior permanent debits creates a new debit row (`admin_percentage_balance_debits`).
- Debits are **non-refundable**: lowering or clearing `contribution` never reduces prior debits.
- Monthly Cash Station settlements never restore these debits.
- Administrative Debt Settlement (month/org-pool recovery) permanently **credits** the pool via `admin_percentage_balance_credits` for the **debt-allocated** portion (`allocated_current_debt + allocated_carried_debt`) when surplus-based debt is recovered. That path does **not** reduce Net Cash or `fund_balance` (distinct from day-level `POST /daily-journals/repay-debt`).
- On settle, the same debt-allocated amount reduces `accumulated_administrative_debt` on the latest journal entry on/before month-end (and later entries), so a new journal day inherits the settled balance. Day `administrative_debt` and `fund_balance` are not changed (preserves Monthly Total / Net Cash).
- Settlement updates Administrative Percentage Balance, remaining administrative debt, and related Cash Station / ADS indicators immediately; **it does not deduct the settlement amount from Net Cash Fund** (debt was already charged against the pool when formed; settle only restores the pool).
- Available surplus capacity for ADS in a month = `max(0, net_cash_fund) − Σ prior ADS recoverable_amount for that project/month` (cumulative cap without mutating Net Cash).
- Allocation priority on execute: Cash Box capacity → Current Admin Debt → Carried Admin Debt.
- ADS list / Cash Station remaining debt read month-end journal `accumulated_administrative_debt` after mutation (do not also subtract settlement ledger totals — that would double-count).

- Remaining deficit = `|fund_balance|` after Pass-1 totals+balances (contribution zeroed).
- Unchanged null/zero when no prior contribution: finance may omit/send zero without error.

### Worked example
- Org fees collected = 200; prior contribution debits = 0 → available = 200
- Yesterday fund = 50; today expense = 200 → Pass-1 fund = −150 → remaining deficit = 150
- Max = min(150, 200) = 150
- Contribute 100 → daily_total = −100, fund_balance = **−50**; day debt includes contribution; debit 100 from pool; available → 100
- Clear contribution later → journal contribution = 0; pool debits stay 100 (non-refundable)

### Administrative Debt worked examples
- Income 200 @ 12% → fee 24; expense 0; fund negative → Case 1 debt = **24**
- Income 500; fee 50; admin expense 80; surplus covers expense → debt = **0**; uncovered = 0
- Income 60; fee 7.2; admin expense 100; surplus 52.8 → fund **0**; uncovered **47.2**; debt **0**
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
| Negative balance, fee available | Case 1 debt = min(\|balance\|, full same-day administrative_fee) |
| Negative balance, fee 0 | Debt 0 (negative alone never creates debt) |
| Admin expense uncovered | Transferred via `uncovered_administrative_expense`; never debt or extra deficit |
| Admin expense covered by surplus | Reduces `fund_balance` only; `uncovered = 0` |
| Admin fee present with uncovered expense | Fee not used for expense; separate Case 1 only if fund deficit |
| Unused prior-day admin fee | Must not cover later deficit or expense |
| Contribution | Requires Pass-1 deficit + available admin percentage balance; reduces fund deficit via daily_total; adds amount to debt; permanently debits org admin pool |
| Contribution clear/lower | Super-admin only; does **not** refund admin percentage pool debits |
| Contribution re-save | Recomputes from same fund-consumption base (never compounds); additional pool debit only for increases |
| Surplus day after prior debt | Surplus kept; accumulated unchanged until repay endpoint |
| Pass-2 preserve | Fee / op / admin expense frozen from Pass 1 |
| Non-active projects | Rejected on save |
| Editing one date | Does not auto-recalculate later dates |
| Project delete | Blocked if any journal entries exist |

---

## Quick formula checks

```
daily_total(1000,50,100,120,30) = 800
coverage(500, 100) → covered 100, uncovered 0, fund 400
coverage(60, 100) → covered 60, uncovered 40, fund 0
calculateAdministrativeDebt(fund=-60, fee=0) = 0
calculateAdministrativeDebt(fund=-100, fee=24) = 24
calculateAdministrativeDebt(fund=370, fee=50) = 0
applyDebt(base_fund=-1100, fee=100, contribution=30) = 130
applyDebt(base_fund=-1100, fee=100, contribution=40) = 140
fund_balance(1000,-300) = 700; fund_balance(300,-900) = -600
fund_balance(-400,250) = -150; fund_balance(-400,800) = 400
accumulated(10,24) = 34
admin_fee(1000 @ 15%) = 150
repay(150, debt=0, acc=100) → (50, 0, 0)
repay(40, 0, 100) → (0, 0, 60)
repay(30, 20, 50) → (0, 0, 20)  // today first, then accumulated
```

When implementing, changing, or reviewing Daily Journal logic, preserve this exact order, these formulas, and these validations. Do not auto-repay debt on save. Do not use administrative fee to cover administrative expense. Uncovered administrative expense flows to Administrative Fund `project_administration` only, and the day's total administrative percentage flows to the display-only `total_administrative_percentage` column only — never merge the two or let either reach administrative income twice. Case 1 uses same-day full admin fee for deficit only; unused fee does not carry forward. Contribution requires a Pass-1 fund deficit; re-saves must not compound. Do not recalculate fee/op/admin expense on Pass 2.
