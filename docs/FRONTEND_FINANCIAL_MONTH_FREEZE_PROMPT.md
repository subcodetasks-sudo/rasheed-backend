# Frontend: month-end financial freeze (Cash Station + Monthly Summary)

## Behavior

When a calendar month ends (e.g. on **1 Sep**), the backend freezes **August** for both **Cash Station** and **Monthly Summary**.

- Locked months are **read-only**: journal writes, settlements, contributions, ADS, and admin inventory movements that affect that month return **422** with `messages.financial_month_locked`.
- The **current** calendar month stays live and never freezes until the next month starts.
- Carry-forward **from** a locked month into the next month is still allowed.

## API fields

Both `GET /v1/cash-station` and `GET /v1/monthly-summary` now include:

| Field | Type | Meaning |
|-------|------|---------|
| `locked` | bool | `true` when serving the frozen snapshot |
| `frozen_at` | string\|null | ISO-8601 timestamp when locked; `null` if live |

When `locked === true`, disable mutate actions for that month in the UI (settlements, contributions, debt settlement, editing journal days in that month).

## Scheduling

Freeze runs daily via Artisan `financial-months:freeze-closed` (scheduled ~00:05). First deploy run backfills all prior months with financial activity.
