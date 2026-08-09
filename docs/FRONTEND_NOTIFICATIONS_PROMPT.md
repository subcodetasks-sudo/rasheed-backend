# Frontend prompt: Notifications module

Paste this into the frontend agent or docs. It reflects the Rashid API Notifications module as implemented. Do not invent endpoints, Socket.IO for notifications, or per-user inbox semantics.

---

## Product rules

- **Global activity feed** — every authorized user sees the same business events. Not a private per-user inbox.
- **Roles allowed:** `super-admin` | `finance` | `inventory` (403 otherwise).
- **System-generated only** — no create/update/delete notification APIs for users. Stock alerts and CRUD activity appear automatically.
- **Base URL:** `/api/v1/...`
- **Auth:** Sanctum Bearer on every JSON route. Header: `Authorization: Bearer <token>`.
- **Locale:** optional `Accept-Language` / app locale middleware (`ar` default). User-facing strings may be Arabic or English from the API.
- **Page type vocabulary (UI + filters):** only `urgent` | `warning` | `info`. Do **not** filter or display DB enums (`activity`, `success`, `danger`, …) in the Notifications page.

---

## Type mapping (labels only)

API already maps stored DB types. Use these page keys in the UI:

| Page `type` | Meaning | Comes from DB |
|-------------|---------|----------------|
| `urgent` | Critical / out of stock | `danger` |
| `warning` | Regular alerts + CRUD activity | `activity`, `success`, `warning` |
| `info` | Informational | `info` |

Statistics cards use the same keys: `total`, `urgent`, `warning`, `info`.

---

## Notification item shape

List, show, mark-read response `data`, and SSE `notification.created` `data` share this shape:

```json
{
  "id": 12,
  "type": "urgent",
  "title": "Out of stock",
  "details": "Paper (P-001) is out of stock.",
  "actor": {
    "uuid": "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee",
    "full_name": "Admin User"
  },
  "project": {
    "id": 10,
    "name": "مشروع الصحة"
  },
  "created_at": "2026-08-09T10:00:00.000000Z",
  "read_at": null
}
```

| Field | Notes |
|-------|--------|
| `id` | Stable numeric id — use for React keys and dedupe |
| `type` | `urgent` \| `warning` \| `info` |
| `title` | Short heading |
| `details` | Body text (quotes stripped; project names may be live-resolved) |
| `actor` | Who triggered it, or `null` |
| `project` | Related project, or `null` |
| `created_at` | ISO-8601 |
| `read_at` | ISO-8601 when **this user** marked it read; otherwise `null` |

`read_at` is **personal**. Marking read for user A does not change user B’s `read_at`.

---

## Success envelope

JSON endpoints use the shared API envelope:

```json
{
  "success": true,
  "message": "...",
  "data": { }
}
```

List also includes pagination `meta` / `links` (same pattern as other list endpoints).

Typical errors: **401** unauthenticated, **403** wrong role, **404** missing notification id, **406** on stream when `Accept: application/json`.

---

## Endpoints

All paths below are under `/api/v1`. All require auth + allowed role unless noted.

### 1. List notifications

`GET /notifications`

| Query | Description |
|-------|-------------|
| `per_page` | Page size (default 15, max 100) |
| `filter[type]` | `urgent` \| `warning` \| `info` |
| `filter[unread]` | `1` or `true` — only items this user has not marked read |

**Do:** paginate the feed; apply type tabs with page vocabulary.  
**Do not:** POST to create notifications.

Example success `data`: array of notification items (shape above).

---

### 2. Show one notification

`GET /notifications/{id}`

- Returns one item (same shape as list).
- **Does not** mark as read. Use the mark-read endpoint explicitly.
- **404** if id does not exist.

---

### 3. Statistics (page cards)

`GET /notifications/statistics`

```json
{
  "total": 10,
  "urgent": 2,
  "warning": 6,
  "info": 2
}
```

Counts are over the **global** feed (not per-user unread counts). Use for Total / Urgent / Warning / Info cards.

---

### 4. Mark one as read

`POST /notifications/{id}/read`

- No body required.
- Idempotent for the current user.
- Returns the notification with `read_at` set.
- **404** if missing.

---

### 5. Mark all as read

`POST /notifications/read-all`

- No body required.
- Marks every currently unread row for **this user** only.

```json
{
  "marked": 3
}
```

---

### 6. SSE stream (realtime)

`GET /notifications/stream`

Realtime delivery for **notifications only**. Do **not** use Socket.IO / Pusher / WebSockets for this module (other modules may still use Socket.IO).

| Concern | Rule |
|---------|------|
| Accept | Must allow SSE: `Accept: text/event-stream`. If the client sends only `application/json` (typical axios), API returns **406**. |
| Auth | Prefer `fetch` + `ReadableStream` with `Authorization: Bearer`. Native `EventSource` **cannot** set Bearer headers — use it only with Sanctum cookie auth on a stateful domain. **Do not** put long-lived tokens in the query string. |
| Page load | **Do not** await the stream in `Promise.all` / initial page spinner. Load list + statistics as normal JSON; open SSE separately. |
| Dev server | Avoid `php artisan serve` when testing SSE + other APIs (single-threaded). Prefer Apache/XAMPP. |

#### SSE wire format (examples)

```text
retry: 3000

event: stream.ready
data: {"cursor":11,"message":"..."}

: heartbeat

event: notification.created
id: notification-12
data: {"id":12,"type":"urgent","title":"...","read_at":null,...}

event: stream.ended
data: {"reason":"max_duration","cursor":12,"retry_ms":3000}
```

| Event | Meaning |
|-------|---------|
| `retry:` | Suggested reconnect delay (ms) |
| `stream.ready` | Connected; no SSE `id` (does not corrupt cursor) |
| `notification.created` | New (or replayed) notification; payload = list item shape; SSE `id` = `notification-{dbId}` |
| `: heartbeat` | Keep-alive comment |
| `stream.ended` | Clean close. Reasons include `max_duration` (~25s window) or `unauthenticated`. Reconnect using `retry:` unless auth failed — then refresh login, do not loop forever. |

#### Reconnect and missed events

- Send `Last-Event-ID: notification-{id}` (or bare numeric id) on reconnect, or query `last_event_id`.
- Fresh connection **without** Last-Event-ID starts at the latest id (no full history dump). Sync history via `GET /notifications`.
- After reconnect: re-fetch list (and optionally statistics); **dedupe by numeric `data.id`**.

Env knobs (backend): `NOTIFICATIONS_SSE_POLL_SECONDS`, `NOTIFICATIONS_SSE_HEARTBEAT_SECONDS`, `NOTIFICATIONS_SSE_MAX_DURATION_SECONDS`, `NOTIFICATIONS_SSE_RETRY_MILLISECONDS`, etc.

---

## Recommended UI flow

```text
Page load
  → GET /notifications + GET /notifications/statistics   (JSON, can Promise.all)
  → Open GET /notifications/stream                        (separate; never block UI on it)

SSE notification.created
  → Upsert into local list by numeric id
  → Optionally refresh statistics

User opens / marks one
  → GET /notifications/{id} if detail view needed (does not mark read)
  → POST /notifications/{id}/read → update local item read_at

Mark all
  → POST /notifications/read-all → clear unread badges / refresh list+stats as needed

SSE stream.ended (max_duration)
  → Reconnect with Last-Event-ID + sync list API

SSE stream.ended (unauthenticated) / 401
  → Stop reconnect loop; run existing auth refresh/logout flow
```

### Deduplication

The same notification can arrive from:

- Initial list fetch
- SSE live event
- SSE replay after reconnect
- Mark-read response

Always key/upsert by **`id`** (numeric). Do not blindly append.

### Multiple tabs

Each tab may hold its own SSE connection. Dedupe per tab by `id`. Read state is per user: mark-read in one tab should update that tab’s local state; other tabs learn via refetch, focus sync, or the next list/SSE cycle (there is no `notification.read` SSE event today).

---

## Inventory stock alerts

When an item’s balance **crosses** into low stock (`≤ minimum_stock_level` and `> 0`) or out of stock (`≤ 0`), the backend writes into this same feed:

- Out of stock → page type `urgent`
- Low stock → page type `warning`

There is **no** separate inventory-notifications API. Show them on the Notifications page like any other item.

---

## Domain-generated financial notifications

These are created by the backend after successful domain operations (not by the frontend).

### Administrative debt alert (active condition)

- Page `type`: `warning`
- One active row per project while tip `accumulated_administrative_debt` &gt; 0
- Updated in place on partial payment; **removed** when remaining debt reaches 0
- Typical title: Administrative Debt / دين إداري
- Details include project name + remaining amount
- Do not treat as historical success; it is a live alert

### Monthly carry-forward success

- Page `type`: `info`
- After successful Cash Station carry-forward only
- Title (AR): `تم ترحيل الشهر بنجاح`
- Details include closed month (`month`/`year`) and backend `executed_at`
- Deduped by `carry_id` / month key — retries must not duplicate UI rows (dedupe by notification `id`)

### Administrative debt payment (ADS surplus settlement)

- Page `type`: `info`
- After successful `AdministrativeDebtSettlement` create only
- Title (AR): `تم سداد الدين الإداري`
- Details: project name, paid amount, remaining debt after payment
- Deduped by `settlement_id`
- Historical — do not delete when later debt changes

---

## Do / do not checklist

**Do**

- Gate the Notifications nav for `super-admin`, `finance`, and `inventory`.
- Use page types `urgent` | `warning` | `info` for filters, badges, and cards.
- Treat list/show/SSE payload as one schema.
- Open SSE outside the critical JSON load path; reconnect with Last-Event-ID + list sync.
- Mark read only via `POST .../read` or `POST .../read-all`.

**Do not**

- Call Socket.IO for notifications.
- Create/delete notifications from the client.
- Put the stream URL in axios/`Promise.all` expecting a finished JSON body.
- Pass access tokens as query params for SSE.
- Assume `read_at` is shared across users.
- Assume show or list auto-marks items as read.
- Invent unread-count fields on statistics (stats are global type totals).

---

## Quick reference

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/notifications` | Paginated feed |
| GET | `/notifications/{id}` | Show one (no mark-read) |
| GET | `/notifications/statistics` | `{ total, urgent, warning, info }` |
| POST | `/notifications/{id}/read` | Mark one read |
| POST | `/notifications/read-all` | Mark all read for current user |
| GET | `/notifications/stream` | SSE (`text/event-stream`) |
