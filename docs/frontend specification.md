# Frontend specification

Branch: `feat/auto-admin-draw` vs `main`.

This document is only what the **frontend must consume**. No new endpoints. Auth, prefixes, and JSON envelope (`success`, `message`, `data`) are unchanged.

Money: `formatRounded` fields are **whole units** (`"650"`, `"0"`, `"-81"`). `daily_income` / `daily_expense` / `contribution` still use two decimals when not null (`"1000.00"`).

Do not recompute journal formulas on the client. Render API values.

---

## 1) Daily Journal — new response fields

**Routes (unchanged):** daily journal GET/PUT (and any payload that serializes `DailyJournalEntryResource`).

**New keys on each entry** (after `accumulated_administrative_debt`):

| Key | Type | Meaning | Arabic label |
|-----|------|---------|--------------|
| `administrative_pool_relief` | string (rounded) | Amount this project **received** from other projects’ unused same-day admin fee (beneficiary). | كم أخذ من النسبة الإدارية |
| `administrative_pool_relief_given` | string (rounded) | Amount this project **gave** from its unused same-day admin fee to cover others’ deficits (source). | كم أعطى من النسبة الإدارية |
| `administrative_fee_remaining` | string (rounded) | Unused same-day admin fee **after** Case 1 (own deficit) and Stage 3 (given to others). Same day only; does not carry forward. | النسبة الإدارية المتبقية |

Default `"0"`. A project is not both source and beneficiary on the same day.

### Full entry `data` item (current)

```json
{
  "id": 12,
  "project": {
    "id": 3,
    "name": "مشروع أ",
    "category": { "id": 1, "name": "إطعام" },
    "administrative_exempt": false
  },
  "daily_income": "1000.00",
  "daily_expense": "1200.00",
  "contribution": "0.00",
  "administrative_expense": "0",
  "uncovered_administrative_expense": "0",
  "project_administration_settled": "0",
  "outstanding_project_administration": "0",
  "administrative_fee": "200",
  "operational_deduction": "0",
  "daily_total": "-400",
  "fund_balance": "0",
  "administrative_debt": "400",
  "accumulated_administrative_debt": "400",
  "administrative_pool_relief": "200",
  "administrative_pool_relief_given": "0",
  "administrative_fee_remaining": "0",
  "status": "balanced"
}
```

Source on the same day would look like:

```json
{
  "administrative_pool_relief": "0",
  "administrative_pool_relief_given": "200",
  "administrative_fee_remaining": "400",
  "fund_balance": "4200",
  "status": "surplus"
}
```

### Per-entry `status`

From rounded `fund_balance` (same rule as Cash Station):

| Value | When |
|-------|------|
| `surplus` | `round(fund_balance) > 0` |
| `deficit` | `round(fund_balance) < 0` |
| `balanced` | otherwise |

### Day `totals` (GET / PUT / PATCH response)

Sibling of `entries` on `DailyJournalResource`. Sums **all active projects for that date** (not just the current page). Projects with no stored row use the same defaults as the empty show row (zeros + previous tip for `fund_balance` / outstanding / accumulated debt).

```json
{
  "journal_date": "2026-08-01",
  "available_administrative_percentage_balance": 100,
  "operational_deduction": 1081,
  "totals": {
    "daily_income": "5000.00",
    "daily_expense": "1200.00",
    "contribution": "0.00",
    "administrative_expense": "0",
    "uncovered_administrative_expense": "0",
    "project_administration_settled": "0",
    "outstanding_project_administration": "0",
    "administrative_fee": "800",
    "operational_deduction": "0",
    "daily_total": "3000",
    "fund_balance": "4200",
    "administrative_debt": "400",
    "accumulated_administrative_debt": "400",
    "administrative_pool_relief": "200",
    "administrative_pool_relief_given": "200",
    "administrative_fee_remaining": "400",
    "status": "surplus"
  },
  "entries": []
}
```

`totals.status` is from the **sum** of `fund_balance` across the day. Use `totals` for the footer row; do not sum the current page on the client.

### Behaviour vs `main`

- Stage 3 (same-day admin-percentage pool) runs **after** `daily_total` and Case 1.
- Beneficiary order when the pool is scarce: projects with **today's** deficit (`daily_total < 0`) before
  **carried-only** deficits (`daily_total >= 0`); then `project_id` ascending within each group.
- `daily_total` is **not** recalculated. Admin fee is already inside `daily_total`.
- Stage 3 only moves **صافي الصندوق**:
  - beneficiary: `fund_balance` ↑, `administrative_debt` ↑, `administrative_pool_relief` ↑
  - source: `fund_balance` ↓, `administrative_pool_relief_given` ↑
- Org-wide sum of `fund_balance` is unchanged (zero-sum).

**UI:** keep `daily_total` and `fund_balance` as separate columns. Do not subtract `administrative_pool_relief_given` from `daily_total` or `administrative_fee`.

---

## 2) Monthly Summary — same keys, new meaning

**Route (unchanged):** `GET /api/v1/monthly-summary?month={1-12}&year={yyyy}`

No new keys. `project_net_result` **meaning changed**.

| Key | vs `main` |
|-----|-----------|
| `project_net_result` | **Was** Cash Station `monthly_total` (operating result ≈ aggregated daily total). **Now** latest journal `fund_balance` with `journal_date <=` month end (`calculation_date`). No entry → `"0"`. |
| `net_result_state` | Still `surplus` \| `deficit` \| `neutral`, now from the **rounded displayed** net cash (so `-0.07` shown as `"0"` is `neutral`). |

Label `project_net_result` as **صافي الصندوق**, not إجمالي اليوم / نتيجة الشهر التشغيلية. Keep the JSON key.

### Project row `data.projects[]` (current)

```json
{
  "project_id": 3,
  "project_name": "مشروع أ",
  "project_type": "إطعام",
  "project_status": "subject",
  "project_net_result": "650",
  "net_result_state": "surplus",
  "administrative_debt": "40",
  "total_received_contributions": "0",
  "total_deducted_contributions": "0"
}
```

Envelope still includes `month`, `calculation_date`, `carried_forward_from_previous`, `contributions`.

### Contributions

Contributor / beneficiary option lists and the store max amount use the same journal **`fund_balance` tip** as Monthly Summary `project_net_result` / Daily Journal صافي الصندوق (as of month end). They do **not** use Cash Station `net_cash_fund`.

| Endpoint field | Meaning |
|----------------|---------|
| `available_surplus` | `max(0, fund_balance tip)` |
| `remaining_need` (`fund_deficit`) | `abs(min(0, fund_balance tip))` |
| `remaining_need` (`administrative_debt`) | outstanding accumulated admin debt (unchanged) |

| Type | Card effect |
|------|-------------|
| `fund_deficit` | Beneficiary journal `fund_balance` (and thus `project_net_result`) **increases**. Contributor journal `fund_balance` / `project_net_result` **decreases** by the same amount. Also show `total_deducted_contributions` / `total_received_contributions`. |
| `administrative_debt` | Reduces beneficiary `administrative_debt`. Contributor journal `fund_balance` / `project_net_result` **decreases**. Does not change beneficiary cash. |

---

## 3) Cash Station — no new keys, two behaviour changes

**Route (unchanged):** `GET /api/v1/cash-station?month=&year=`

Keys unchanged: `monthly_total`, `net_cash_fund`, `added_contribution`, `deducted_contribution`, `status`, etc.

1. **`administrative_debt` monthly-summary contributions** no longer inflate the beneficiary’s cash:
   - contributor: `deducted_contribution` still increases, `net_cash_fund` still drops
   - beneficiary: `added_contribution` stays `0` for that transfer; `remaining_administrative_debt` drops
   - `fund_deficit` contributions and untyped settlements still add cash to the beneficiary
2. **`status`** (`surplus` / `deficit` / `balanced`) is derived from the **rounded displayed** `net_cash_fund`, not the raw float (sub-unit residue that renders as `"0"` is `balanced`).

`monthly_total` is still the month operating result, **not** journal `fund_balance`. That is why Monthly Summary was changed separately.

---

## 4) Administrative Fund — those three lines are صادر, not وارد

**Screen:** الصندوق الإداري (`GET /api/v1/administrative-fund`). Keys unchanged.

The UI currently groups these under **الوارد**. They are **صادر** (outflows from the admin fund). The backend already subtracts them when computing `total_income`. Do not treat them as income cards.

| Arabic (current UI copy) | API key | Source | Side |
|--------------------------|---------|--------|------|
| إداري المشروعات — من الصحيفة اليومية، لم تتم تغطيته من فائض المشروعات | `project_administration` (tip display). The amount subtracted in `total_income` is uncovered journal expense (`uncovered_administrative_expense` / `project_administration_income`). | Daily journal | **صادر** |
| مساهمة الصناديق — من عمليات المساهمات | `cash_fund_contributions` | Contribution operations | **صادر** |
| مساهمة أفراد — إدخال يدوي | `individual_contributions` | Manual day input | **صادر** |

Formula already on the backend (`إجمالي إداري الصناديق` / `total_income`):

```
total_income =
  total_administrative_percentage
  − (uncovered_administrative_expense + cash_fund_contributions + individual_contributions)
  + debt_recovery
```

**وارد** on this screen: `total_administrative_percentage` (وارد النسبة الإدارية) and `debt_recovery`.

Move the three rows into the **صادر** group (same visual language as `operational_administration` / `asset_administration`). Keep the JSON keys. Do not add them into `total_income` on the client.

---

## 5) Operational Rate — operational in / out / net (not صندوق daily income)

**Route (unchanged):** `GET /api/v1/operational-rate?month={1-12}&year={yyyy}`

This page is **not** about وارد يومي للصندوق (`total_income` = sum of journal `daily_income`). Hide that column/card.

Show only operational flow:

| UI | API | Arabic |
|----|-----|--------|
| وارد تشغيلي يومي | `daily_records[].operational_deduction` | الوارد التشغيلي اليومي |
| صادر تشغيلي يومي | `daily_records[].operational_expense` | الصادر التشغيلي اليومي |
| صافي تشغيلي يومي | `daily_records[].operational_net` | الصافي التشغيلي |
| مستطيل أعلى الصفحة | `summary.total_operational_expense` | إجمالي الصادر التشغيلي |

`operational_expense` comes from الصندوق التشغيلي (`operational_fund_days.operational_expense`). A 200 outgoing on 2026-08-01 appears on that day’s row even if there is no journal entry.

`operational_net` = `operational_deduction − operational_expense` (backend). Do not recompute.

**New / extra keys (keep existing keys):**

```json
{
  "summary": {
    "relative_operational_deduction": "0",
    "fixed_operational_deduction": "0",
    "total_operational_deduction": "500",
    "total_operational_expense": "200",
    "total_operational_net": "300"
  },
  "daily_records": [
    {
      "date": "2026-08-01",
      "day_name": "Saturday",
      "total_income": "1000",
      "operational_deduction": "500",
      "operational_expense": "200",
      "operational_net": "300",
      "administrative_percentage": "0"
    }
  ],
  "monthly_totals": {
    "total_income": "1000",
    "total_operational_deduction": "500",
    "total_operational_expense": "200",
    "total_operational_net": "300",
    "total_administrative_percentage": "0"
  }
}
```

Optional header cards: `summary.total_operational_deduction` (إجمالي الوارد التشغيلي), `summary.total_operational_net` (إجمالي الصافي التشغيلي). The required new rectangle is **إجمالي الصادر التشغيلي** = `summary.total_operational_expense`.

Realtime: saving operational expense on الصندوق التشغيلي now also broadcasts `operational-rate.updated` for that month.

---

## What did **not** change

- No new REST routes for pool relief allocations (history table is backend-only).
- Dashboard, Reports Center, Administration Rates: no new response keys in this branch (Operational Rate **does** — see §5).
- Cash Station field names.

---

## Frontend acceptance

1. Daily journal shows **received** (`administrative_pool_relief`), **given** (`administrative_pool_relief_given`), and **remaining** (`administrative_fee_remaining`).
1b. Daily journal shows per-entry `status` and day `totals` (all columns + `totals.status`).
2. Monthly summary cards show month-end **صافي الصندوق** (`project_net_result` = `fund_balance` tip), including Stage 3 on the source.
3. No client formula that rebuilds `daily_total` after pool relief.
4. Cash Station: debt-type contributions do not increase beneficiary `added_contribution` / `net_cash_fund`.
5. Contribution option lists use journal `fund_balance` tip (same as `project_net_result`), not Cash Station `net_cash_fund`. Contributor tip drops after a contribution by that amount.
6. Administrative Fund: إداري المشروعات، مساهمة الصناديق، مساهمة أفراد are **صادر**, not **وارد**.
7. Operational Rate: hide `total_income`. Show daily `operational_deduction` / `operational_expense` / `operational_net`. Top card `summary.total_operational_expense` = إجمالي الصادر التشغيلي.
