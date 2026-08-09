# Frontend prompt: roles, seed accounts, and API access

Paste this into the frontend agent or docs. It reflects what the Rashid API enforces via Spatie `role:` middleware on routes. Do not invent extra roles.

---

## System roles

Exactly three roles (`guard: web`):

| Role slug | Purpose |
|-----------|---------|
| `super-admin` | Full access to every module and admin endpoints |
| `finance` | Financial operations: journals, cash views, settlements, project read + deductions |
| `inventory` | Inventory CRUD + read projects/categories + advanced reports |

Product naming: **super-admin**, **fund manager** (`finance`), **inventory manager** (`inventory`). API role strings are the slugs above.

---

## Seed accounts (after `php artisan db:seed`)

Login: `POST /api/v1/auth/login` with body `{ "user_name", "password" }` (not email).

| Role | `user_name` | Password | Email |
|------|-------------|----------|-------|
| `super-admin` | `super_admin` | `password123` | `admin@system.com` |
| `finance` | `finance_user` | `password123` | `finance@system.com` |
| `inventory` | `inventory_user` | `password123` | `inventory@system.com` |

Auth response includes Sanctum Bearer token (~2h expiry). Send `Authorization: Bearer <token>` on protected routes. Refresh: `POST /api/v1/auth/refresh` (public, rate-limited). Logout: `POST /api/v1/auth/logout` (any authenticated user).

---

## How to gate the UI

- Hide or disable nav/actions the user’s role cannot call; the API returns **403** if middleware blocks.
- A user has **one** of the three roles for seed accounts; treat `super-admin` as a superset of finance + inventory.
- Prefer checking the role slug from the login payload (`user.roles` / equivalent), not hardcoding emails.

---

## Access by role (route middleware)

### Any authenticated (no role check)

| Method | Path | Notes |
|--------|------|-------|
| POST | `/auth/logout` | Auth required |
| GET | `/realtime/auth` | Auth + `CheckStatus` |

### Public (no auth)

| Method | Path |
|--------|------|
| POST | `/auth/login` |
| POST | `/auth/refresh` |

### Media (no role middleware today)

| Method | Path |
|--------|------|
| POST | `/media` |
| GET | `/media/{id}` |
| GET | `/media/{id}/download` |
| DELETE | `/media/{id}` |

---

### `super-admin` only

| Module | Method | Path |
|--------|--------|------|
| Settings | GET/PUT | `/settings/general` |
| Settings | GET/PUT | `/settings/monthly-employees` |
| Auth users | GET/POST | `/auth/users` |
| Auth users | PATCH/DELETE | `/auth/users/{user}` |
| Authorization | GET | `/roles` |
| Authorization | GET | `/users` |
| Authorization | POST | `/users/{user}` (update) |
| Authorization | DELETE | `/users/{user}` |
| Authorization | POST | `/users/{user}/status` |
| Projects | POST | `/projects` |
| Projects | PATCH/DELETE | `/projects/{project}` |
| Projects | POST | `/projects/{project}/archive` |
| Projects | POST | `/projects/{project}/restore` |
| Projects | PATCH | `/projects/financial-settings` |
| Categories | POST | `/categories` |
| Categories | PATCH/DELETE | `/categories/{category}` |

---

### `super-admin` \| `finance`

| Module | Method | Path |
|--------|--------|------|
| Projects | GET | `/projects/financial-settings` |
| Projects | POST | `/projects/calculate-deductions` |
| Projects | POST | `/projects/{project}/calculate-deduction` |
| Daily Journal | GET/PUT/PATCH | `/daily-journals` |
| Daily Journal | POST | `/daily-journals/repay-debt` |
| Cash Station | GET | `/cash-station` |
| Cash Station | POST | `/cash-station/carry-forward` |
| Cash Station | POST | `/cash-station/settlements` |
| Cash Station | DELETE | `/cash-station/settlements/{settlement}` |
| Monthly Summary | GET | `/monthly-summary` |
| Monthly Summary | GET | `/monthly-summary/contributor-options` |
| Monthly Summary | GET | `/monthly-summary/beneficiary-options` |
| Monthly Summary | POST | `/monthly-summary/contributions` |
| Monthly Summary | DELETE | `/monthly-summary/contributions/{settlement}` |
| Cash Fund Expenses | GET | `/cash-fund-expenses` |
| Administration Rates | GET | `/administration-rates` |
| Administrative Fund | GET | `/administrative-fund` |
| Administrative Fund | PUT | `/administrative-fund/{date}` |
| Operational Fund | GET | `/operational-fund/day` |
| Operational Fund | GET | `/operational-fund` |
| Operational Fund | PUT | `/operational-fund/{date}` |
| Operational Rate | GET | `/operational-rate` |
| Dashboard | GET | `/dashboard` |
| Reports Center | GET | `/reports-center` |
| Administrative Debt Settlement | GET/POST | `/administrative-debt-settlements` |

---

### `super-admin` \| `inventory`

| Module | Method | Path |
|--------|--------|------|
| Inventory | GET/POST | `/inventory/categories` |
| Inventory | PATCH/DELETE | `/inventory/categories/{category}` |
| Inventory | GET/POST | `/inventory/items` |
| Inventory | GET | `/inventory/items/{item}` |
| Inventory | GET | `/inventory/movements` |
| Inventory | POST | `/inventory/movements/incoming` |
| Inventory | POST | `/inventory/movements/outgoing` |

---

### `super-admin` \| `finance` \| `inventory`

| Module | Method | Path |
|--------|--------|------|
| Projects | GET | `/projects` |
| Projects | GET | `/projects/{project}` |
| Categories | GET | `/categories` |
| Advanced Reports | GET | `/advanced-reports` |

---

## Role capability summary (for menus)

### `super-admin`

- Everything below for finance and inventory
- Settings, user/role admin
- Create / update / delete / archive / restore projects and categories
- Update project financial settings

### `finance`

**Allowed:** project/category list & show; financial settings GET; calculate deductions; daily journal; cash station; monthly summary; cash fund expenses; administration rates; administrative fund; operational fund; operational rate; dashboard; reports center; advanced reports; administrative debt settlement.

**Denied (403):** settings; user/role admin; project/category writes; update financial settings; inventory module.

### `inventory`

**Allowed:** full inventory module; project list & show; category list; advanced reports.

**Denied (403):** settings; user/role admin; project/category writes; financial settings & deductions; daily journal and all other finance-only modules listed under `super-admin|finance`.

---

## Base URL

All paths above are under `/api/v1` (e.g. `http://localhost:8000/api/v1`).

---

## Source of truth

- Roles seed: `Modules/Authorization/database/seeders/RolesAndPermissionsSeeder.php`
- Users seed: `database/seeders/DatabaseSeeder.php`
- Access: `role:...` middleware on each `Modules/*/routes/api.php`
