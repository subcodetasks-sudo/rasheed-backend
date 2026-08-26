# Frontend: outgoing FIFO price breakdown

## Problem

Outgoing inventory movements that consume more than one incoming batch get a single `unit_price` on the movement. That value is the **FIFO weighted average** (`total_cost / quantity`), not the price of one purchase.

Example: 5 tents @ 350 and 2 tents @ 300 →

- `quantity` = 7
- `total_cost` = 2350 (correct)
- `unit_price` = ~335.71 (average — misleading if shown alone as “سعر الوحدة”)

## Rule

Do **not** present `unit_price` alone as the purchase unit price when `consumptions.length > 1`.

Show a **per-batch breakdown** from `consumptions` (quantity + unit cost per incoming/opening layer), and keep showing `total_cost`.

When `consumptions.length === 1`, a single unit cost is fine (same as that batch’s `unit_cost`).

## API shape (outgoing movement)

`unit_price` and `total_cost` are unchanged. Each consumption line includes batch labels for display:

```json
{
  "id": 123,
  "type": "outgoing",
  "quantity": "7.00",
  "unit_price": "335.71",
  "total_cost": "2350.00",
  "consumptions": [
    {
      "batch_id": 10,
      "quantity": "5.00",
      "unit_cost": "350.00",
      "line_cost": "1750.00",
      "source_type": "incoming",
      "received_at": "2026-08-01T10:00:00.000000Z",
      "source_movement_id": 88
    },
    {
      "batch_id": 11,
      "quantity": "2.00",
      "unit_cost": "300.00",
      "line_cost": "600.00",
      "source_type": "incoming",
      "received_at": "2026-08-05T10:00:00.000000Z",
      "source_movement_id": 91
    }
  ]
}
```

`source_type` is `opening` or `incoming`. Opening batches have `source_movement_id: null`.

List movements (`GET /api/v1/inventory/movements`) and create outgoing (`POST /api/v1/inventory/movements/outgoing`) both return this when consumptions are present.

## UI suggestions (Arabic)

| Surface | Suggestion |
|--------|------------|
| Column / label | كمية وسعر كل وارد |
| Multi-batch cell | سطور مثل `5 × 350` و`2 × 300` |
| Footer / total | التكلفة الإجمالية = `total_cost` |
| Tooltip on average | إن ظهر المتوسط: «متوسط موزون (FIFO) — راجع تفصيل الوارد» |

Do not change backend costing; this is display-only.

---

## List page: separate cost totals + monthly filter

`GET /api/v1/inventory/movements` already returns a **`summary`** object with **separate** totals (same filters as the list):

| Field | Arabic label suggestion | Meaning |
|--------|-------------------------|---------|
| `summary.total_incoming_value` | إجمالي تكلفة الوارد | Sum of `total_cost` for incoming movements |
| `summary.total_outgoing_value` | إجمالي تكلفة الصادر | Sum of `total_cost` for outgoing movements |
| `summary.net_movement_value` | الصافي | Incoming − outgoing |

Show **وارد** and **صادر** totals separately in the footer — do not collapse them into one number unless you also show net.

### Monthly filter

Send both query params together:

```
GET /api/v1/inventory/movements?filter[month]=8&filter[year]=2026
```

- Scopes list rows and `summary` to that calendar month (`movement_date`).
- `filter[month]` and `filter[year]` are required together (1–12 / year ≥ 2000).
- If month/year are set, they **override** `filter[movement_date_from]` / `filter[movement_date_to]` for that request.
- Without month/year, the existing from/to date range still works.

Opening stock is not a movement row, so it is not included in `total_incoming_value`.

