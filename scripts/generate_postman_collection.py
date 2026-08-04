#!/usr/bin/env python3
"""Generate Rashid API Postman collection (role-based, with example responses)."""

from __future__ import annotations

import json
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "Rashid API.postman_collection.json"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def uid() -> str:
    return str(uuid.uuid4())


def header(accept: bool = True, json_body: bool = False, auth: bool = True, locale: bool = False) -> list[dict]:
    h: list[dict] = []
    if accept:
        h.append({"key": "Accept", "value": "application/json", "type": "text"})
    if json_body:
        h.append({"key": "Content-Type", "value": "application/json", "type": "text"})
    if locale:
        h.append({"key": "Accept-Language", "value": "{{locale}}", "type": "text", "description": "ar | en"})
    if auth:
        h.append({"key": "Authorization", "value": "Bearer {{token}}", "type": "text"})
    return h


def url(path: str, query: list[dict] | None = None) -> dict:
    parts = [p for p in path.strip("/").split("/") if p]
    raw = "{{base_url}}/" + "/".join(parts)
    if query:
        qs = "&".join(f"{q['key']}={{{{{q['key']}}}}}" if q.get("value", "").startswith("{{") else f"{q['key']}={q.get('value','')}" for q in query if not q.get("disabled"))
        # Prefer explicit values in raw
        enabled = [q for q in query if not q.get("disabled")]
        if enabled:
            raw += "?" + "&".join(f"{q['key']}={q.get('value','')}" for q in enabled)
    return {
        "raw": raw,
        "host": ["{{base_url}}"],
        "path": parts,
        **({"query": query} if query else {}),
    }


def body_raw(obj: dict | list | str) -> dict:
    raw = obj if isinstance(obj, str) else json.dumps(obj, ensure_ascii=False, indent=2)
    return {"mode": "raw", "raw": raw, "options": {"raw": {"language": "json"}}}


def example(
    name: str,
    status: int,
    body: dict | list | str | None,
    *,
    original_request: dict | None = None,
) -> dict:
    if isinstance(body, (dict, list)):
        body_str = json.dumps(body, ensure_ascii=False, indent=2)
    elif body is None:
        body_str = ""
    else:
        body_str = body

    status_text = {
        200: "OK",
        201: "Created",
        400: "Bad Request",
        401: "Unauthorized",
        403: "Forbidden",
        404: "Not Found",
        422: "Unprocessable Entity",
        429: "Too Many Requests",
        500: "Internal Server Error",
    }.get(status, "Response")

    return {
        "name": name,
        "originalRequest": original_request or {},
        "status": status_text,
        "code": status,
        "_postman_previewlanguage": "json",
        "header": [{"key": "Content-Type", "value": "application/json"}],
        "body": body_str,
        "id": uid(),
    }


def req(
    name: str,
    method: str,
    path: str,
    *,
    description: str = "",
    roles: list[str] | None = None,
    enums: str = "",
    query: list[dict] | None = None,
    body: dict | list | str | None = None,
    body_mode: str = "raw",
    formdata: list[dict] | None = None,
    auth_required: bool = True,
    json_body: bool = False,
    locale: bool = False,
    responses: list[dict] | None = None,
    events: list[dict] | None = None,
    no_auth_header: bool = False,
) -> dict:
    roles = roles or []
    role_line = f"**Roles:** `{', '.join(roles)}`" if roles else "**Roles:** public / no role middleware"
    desc_parts = [role_line, description]
    if enums:
        desc_parts.append(f"**Enums / allowed values:**\n{enums}")

    headers = header(
        json_body=json_body and body_mode == "raw",
        auth=auth_required and not no_auth_header,
        locale=locale,
    )

    request: dict = {
        "method": method,
        "header": headers,
        "url": url(path, query),
        "description": "\n\n".join(p for p in desc_parts if p),
    }

    if body_mode == "raw" and body is not None:
        request["body"] = body_raw(body)
    elif body_mode == "formdata" and formdata is not None:
        request["body"] = {"mode": "formdata", "formdata": formdata}

    # Strip Authorization from header list when using collection auth for cleaner UX —
    # we keep explicit Bearer for clarity in role folders.

    item: dict = {
        "name": name,
        "request": request,
        "response": responses or [],
    }
    if events:
        item["event"] = events
    return item


def folder(name: str, items: list, description: str = "") -> dict:
    f: dict = {"name": name, "item": items}
    if description:
        f["description"] = description
    return f


# ---------------------------------------------------------------------------
# Shared response templates
# ---------------------------------------------------------------------------

UNAUTHORIZED = {
    "message": "Unauthenticated.",
}

FORBIDDEN = {
    "message": "User does not have the right roles.",
}

VALIDATION = {
    "message": "The given data was invalid.",
    "errors": {"field": ["The field field is required."]},
}

NOT_FOUND = {
    "message": "No query results for model.",
}

RATE_LIMIT = {
    "success": False,
    "message": "Too many requests. Please try again after 60 seconds.",
}


def ok(message: str, data=None) -> dict:
    payload = {"success": True, "message": message}
    if data is not None:
        payload["data"] = data
    return payload


def fail(message: str, data=None, *, errors=None) -> dict:
    if errors is not None:
        return {"success": False, "message": message, "errors": errors}
    payload = {"success": False, "message": message}
    if data is not None:
        payload["data"] = data
    return payload


LOGIN_SCRIPT = [
    {
        "listen": "test",
        "script": {
            "type": "text/javascript",
            "exec": [
                "if (pm.response.code === 200) {",
                "  const json = pm.response.json();",
                "  const token = json.data?.token;",
                "  if (token) { pm.collectionVariables.set('token', token); }",
                "  const refresh = json.data?.refresh_token;",
                "  if (refresh) { pm.collectionVariables.set('refresh_token', refresh); }",
                "  const role = json.data?.role;",
                "  if (role) { pm.collectionVariables.set('current_role', role); }",
                "  const userId = json.data?.user?.id;",
                "  if (userId) { pm.collectionVariables.set('user_uuid', userId); }",
                "}",
            ],
        },
    }
]

SAMPLE_USER = {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Super Admin",
    "user_name": "super_admin",
    "email": "admin@rashid.test",
    "status": "active",
    "lastLoginAt": "2026-07-28 12:00:00",
    "role": "super-admin",
}

SAMPLE_PROJECT = {
    "id": 1,
    "name": "مشروع صحي ثابت",
    "category": {"id": 1, "name": "صحة"},
    "fund_type": "fixed",
    "status": "active",
    "operational_deduction_type": "fixed",
    "operational_fixed_amount": "154.00",
    "administrative_exempt": False,
    "administrative_fee_percentage": None,
    "archived_at": None,
    "created_by": "550e8400-e29b-41d4-a716-446655440000",
    "updated_by": None,
    "created_at": "2026-07-28 10:00:00",
    "updated_at": "2026-07-28 10:00:00",
}

SAMPLE_CATEGORY = {
    "id": 1,
    "name": "صحة",
    "created_at": "2026-07-28 09:00:00",
    "updated_at": "2026-07-28 09:00:00",
}

SAMPLE_JOURNAL_ENTRY = {
    "id": 1,
    "journal_date": "2026-07-28",
    "project": {
        "id": 1,
        "name": "مشروع صحي ثابت",
        "category": {"id": 1, "name": "صحة"},
        "administrative_exempt": False,
    },
    "daily_income": "1000.00",
    "daily_expense": "100.00",
    "contribution": "0.00",
    "administrative_expense": "0.00",
    "administrative_fee": "120.00",
    "operational_deduction": "154.00",
    "daily_total": "626.00",
    "fund_balance": "626.00",
    "administrative_debt": "0.00",
    "accumulated_administrative_debt": "0.00",
}

# Super-admin contribution covering a real remaining deficit.
# Previous fund balance 50; today expense 200 -> pre-contribution balance -150 (remaining deficit 150).
# Contribution 150 -> daily_total = 0 + 150 - 200 = -50; fund_balance = 50 + (-50) = 0.
SAMPLE_JOURNAL_ENTRY_CONTRIBUTION = {
    "id": 1,
    "journal_date": "2026-07-28",
    "project": {
        "id": 1,
        "name": "مشروع صحي ثابت",
        "category": {"id": 1, "name": "صحة"},
        "administrative_exempt": True,
    },
    "daily_income": "0.00",
    "daily_expense": "200.00",
    "contribution": "150.00",
    "administrative_expense": "0.00",
    "administrative_fee": "0.00",
    "operational_deduction": "0.00",
    "daily_total": "-50.00",
    "fund_balance": "0.00",
    "administrative_debt": "0.00",
    "accumulated_administrative_debt": "0.00",
}

SAMPLE_INVENTORY_CATEGORY = {
    "id": 1,
    "name": "مستهلكات",
    "created_at": "2026-08-04 10:00:00",
    "updated_at": "2026-08-04 10:00:00",
}

SAMPLE_INVENTORY_ITEM = {
    "id": 1,
    "code": "INV-0001",
    "name": "قفازات طبية",
    "category_id": 1,
    "category": {"id": 1, "name": "مستهلكات"},
    "unit": "علبة",
    "project": {"id": 1, "name": "مشروع صحي ثابت"},
    "project_id": 1,
    "latest_incoming_price": "25.50",
    "opening_quantity": "100.00",
    "total_incoming_quantity": "50.00",
    "total_outgoing_quantity": "20.00",
    "current_balance": "130.00",
    "minimum_stock_level": "10.00",
    "notes": "مخزون افتتاحي",
    "created_at": "2026-07-29T10:00:00.000000Z",
    "updated_at": "2026-07-29T12:00:00.000000Z",
}

SAMPLE_INVENTORY_MOVEMENT_IN = {
    "id": 1,
    "inventory_item_id": 1,
    "type": "incoming",
    "quantity": "50.00",
    "unit_price": "25.50",
    "total_cost": "1275.00",
    "beneficiary_project_id": None,
    "expense_type": None,
    "notes": "شراء جديد",
    "movement_date": "2026-07-29",
    "item": SAMPLE_INVENTORY_ITEM,
    "beneficiary_project": None,
    "consumptions": [],
    "created_at": "2026-07-29T11:00:00.000000Z",
}

SAMPLE_INVENTORY_MOVEMENT_OUT = {
    "id": 2,
    "inventory_item_id": 1,
    "type": "outgoing",
    "quantity": "20.00",
    "unit_price": "25.50",
    "total_cost": "510.00",
    "beneficiary_project_id": 1,
    "expense_type": "administrative",
    "notes": "صرف إداري",
    "movement_date": "2026-07-29",
    "item": {
        **SAMPLE_INVENTORY_ITEM,
        "total_outgoing_quantity": "20.00",
        "current_balance": "130.00",
    },
    "beneficiary_project": {"id": 1, "name": "مشروع صحي ثابت"},
    "consumptions": [
        {"batch_id": 1, "quantity": "20.00", "unit_cost": "25.50", "line_cost": "510.00"},
    ],
    "created_at": "2026-07-29T12:00:00.000000Z",
}

ENUMS_DOC = """
## Roles (Spatie `guard: web`)
| Role | Description |
|------|-------------|
| `super-admin` | Full access |
| `finance` | Financial operations, journals, deductions, read projects |
| `inventory` | Inventory module CRUD + read projects & categories |

## Domain enums
| Enum | Values |
|------|--------|
| `FundType` (`fund_type`) | `fixed`, `variable` |
| `ProjectStatus` (`status`) | `active`, `stopped`, `archived` |
| `OperationalDeductionType` (`operational_deduction_type`) | `relative`, `fixed`, `exempt` |
| `InventoryMovementType` (`type`) | `incoming`, `outgoing` |
| `InventoryExpenseType` (`expense_type`) | `operational`, `administrative` |
| List `tab` | `fixed`, `variable`, `archived` |
| User `status` | `pending`, `active`, `suspended`, `rejected`, `banned` |
| Setting `type` | `string`, `integer`, `boolean`, `json`, `decimal` |

## Auth
- Sanctum Bearer token (2h expiry)
- Header: `Authorization: Bearer {{token}}`
- Login body: `{ "user_name", "password" }`
- User route param uses **UUID** (`{user}`)
- User list excludes the logged-in user; a user **cannot delete their own account** (403)

## Operational deduction effective date
- Changing `total_operational_deduction` (Settings or Financial Settings) updates the **configured** value immediately for GET/UI.
- Journal / deduction **calculations** use the rate effective for the journal date from `operational_deduction_rates`.
- A mid-day change never affects **today** or earlier dates; the new amount applies from the **next calendar day** only.
- Recalculating a historical journal still uses the pool that was effective on that journal date.

## Administrative fee percentage effective date
- Changing `admin_fee_percentage` (Settings or Financial Settings) updates the **configured** value immediately for GET/UI.
- Journal / admin-fee **calculations** use the percentage effective for the journal date from `administrative_fee_rates`.
- A mid-day change never affects **today** or earlier dates; the new percentage applies from the **next calendar day** only.
- Recalculating a historical journal still uses the percentage that was effective on that journal date.
- Project `administrative_fee_percentage` remains a create-time display snapshot; calculation uses the effective global rate (exempt projects still pay 0).

## Realtime (Socket.IO)
- Transport: Socket.IO only (no Pusher). Node sidecar: `realtime/` (`npm start`). Frontend: `socket.io-client`.
- Prod `https` → **WSS** (TLS at reverse proxy); local `http` → WS.
- Handshake: connect with `auth: { token: <Sanctum PAT> }`. Server validates via `GET /api/v1/realtime/auth`.
- Client emits `join` / `leave` for rooms. Notifications are **socket-only** (no notification REST CRUD).
- Rooms / events:
  - `notifications` → `notification.created`
  - `daily-journals.{Y-m-d}` → `daily-journal.updated`
  - `cash-station.{YYYY}-{MM}` → `cash-station.updated`
  - `administrative-debt-settlements.{YYYY}-{MM}` → `administrative-debt-settlements.updated`
  - `inventory` → `inventory.item-created`, `inventory.stock-moved`
  - `projects.{id}` → (reserved for project payloads)
- Env (Laravel): `BROADCAST_CONNECTION=socketio`, `SOCKET_IO_URL`, `SOCKET_IO_PATH`, `SOCKET_IO_SECRET`
- Env (realtime/): `PORT`, `LARAVEL_URL`, `SOCKET_IO_SECRET` (must match), `CORS_ORIGIN`

## Base URL
`{{base_url}}` → e.g. `http://localhost:8000/api/v1` or XAMPP public path + `/api/v1`
"""

OP_DEDUCTION_EFFECTIVE_DATE_NOTE = (
    "### Operational deduction effective date\n"
    "Changing `total_operational_deduction` stores the new **configured** value immediately (shown on GET).\n"
    "Daily Journal / deduction math uses the **effective** pool for the journal date "
    "(scheduled via `operational_deduction_rates`).\n"
    "A change on day D never affects journals for D or earlier; the new amount starts on **D+1**.\n"
    "Same-day re-edits only replace the scheduled D+1 rate. Historical recalc keeps the original effective pool."
)

ADMIN_FEE_EFFECTIVE_DATE_NOTE = (
    "### Administrative fee percentage effective date\n"
    "Changing `admin_fee_percentage` stores the new **configured** value immediately (shown on GET).\n"
    "Daily Journal / admin-fee math uses the **effective** percentage for the journal date "
    "(scheduled via `administrative_fee_rates`).\n"
    "A change on day D never affects journals for D or earlier; the new percentage starts on **D+1**.\n"
    "Same-day re-edits only replace the scheduled D+1 rate. Historical recalc keeps the original effective percentage."
)


def std_auth_errors(original: dict, *, include_forbidden: bool = True, include_validation: bool = False, include_not_found: bool = False) -> list:
    out = [
        example("401 Unauthorized", 401, UNAUTHORIZED, original_request=original),
    ]
    if include_forbidden:
        out.append(example("403 Forbidden (wrong role)", 403, FORBIDDEN, original_request=original))
    if include_validation:
        out.append(example("422 Validation Error", 422, VALIDATION, original_request=original))
    if include_not_found:
        out.append(example("404 Not Found", 404, NOT_FOUND, original_request=original))
    return out


# ---------------------------------------------------------------------------
# Endpoint builders
# ---------------------------------------------------------------------------

def login_item(name: str, user_name: str, password: str = "password123", role: str = "super-admin") -> dict:
    path = "auth/login"
    body = {"user_name": user_name, "password": password}
    original = {
        "method": "POST",
        "header": header(json_body=True, auth=False),
        "body": body_raw(body),
        "url": url(path),
    }
    user = {**SAMPLE_USER, "user_name": user_name, "role": role}
    return req(
        name,
        "POST",
        path,
        description="Public. Rate-limited (`rate_limit:auth`). Saves `token`, `current_role`, `user_uuid` on success.",
        roles=[],
        enums="No enums.",
        body=body,
        json_body=True,
        auth_required=False,
        no_auth_header=True,
        events=LOGIN_SCRIPT,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Logged in successfully",
                    {
                        "user": user,
                        "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
                        "refresh_token": "abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ01",
                    },
                ),
                original_request=original,
            ),
            example(
                "422 Invalid credentials",
                422,
                {"message": "The given data was invalid.", "errors": {"user_name": ["These credentials do not match our records."]}},
                original_request=original,
            ),
            example("429 Rate limited", 429, RATE_LIMIT, original_request=original),
        ],
    )


def logout_item() -> dict:
    path = "auth/logout"
    original = {"method": "POST", "header": header(auth=True), "url": url(path)}
    return req(
        "Logout",
        "POST",
        path,
        description="Any authenticated user. Revokes all Sanctum access tokens and refresh tokens for the user. Rate-limited (`rate_limit:user`).",
        roles=["super-admin", "finance", "inventory", "any authenticated"],
        responses=[
            example("200 OK", 200, ok("Logged out successfully"), original_request=original),
            *std_auth_errors(original, include_forbidden=False),
        ],
    )


def realtime_auth_item() -> dict:
    path = "realtime/auth"
    original = {"method": "GET", "header": header(auth=True), "url": url(path)}
    return req(
        "Realtime Auth (Socket.IO handshake)",
        "GET",
        path,
        description=(
            "Any authenticated Sanctum user. Used by the Node Socket.IO sidecar to validate "
            "`auth.token` on connect. Returns user uuid / roles. "
            "**Not** a notifications CRUD endpoint — notifications are delivered only over Socket.IO "
            "(`notifications` room, event `notification.created`). "
            "See collection docs for rooms/events and `SOCKET_IO_*` env."
        ),
        roles=["super-admin", "finance", "inventory", "any authenticated"],
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Realtime authentication successful.",
                    {
                        "id": None,
                        "uuid": "user-uuid",
                        "full_name": "Finance User",
                        "roles": ["finance"],
                    },
                ),
                original_request=original,
            ),
            *std_auth_errors(original, include_forbidden=False),
        ],
    )


def refresh_item() -> dict:
    path = "auth/refresh"
    body = {"refresh_token": "{{refresh_token}}"}
    original = {
        "method": "POST",
        "header": header(json_body=True, auth=False),
        "body": body_raw(body),
        "url": url(path),
    }
    return req(
        "Refresh Token",
        "POST",
        path,
        description=(
            "Public. Rate-limited (`rate_limit:auth`). "
            "Exchanges a valid refresh token for a new access token + rotated refresh token. "
            "The previous refresh token is revoked."
        ),
        roles=[],
        enums="No enums.",
        body=body,
        json_body=True,
        auth_required=False,
        no_auth_header=True,
        events=LOGIN_SCRIPT,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Token has been refreshed successfully.",
                    {
                        "user": SAMPLE_USER,
                        "token": "2|yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy",
                        "refresh_token": "rotatedRefreshToken0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ",
                    },
                ),
                original_request=original,
            ),
            example(
                "422 Invalid or expired refresh token",
                422,
                {
                    "message": "The given data was invalid.",
                    "errors": {
                        "refresh_token": ["Invalid or expired refresh token."]
                    },
                },
                original_request=original,
            ),
            example("429 Rate limited", 429, RATE_LIMIT, original_request=original),
        ],
    )


# --- Users (User module under /auth/users) ---

def auth_users_list() -> dict:
    path = "auth/users"
    query = [
        {"key": "search", "value": "", "description": "Search: full_name, email, user_name", "disabled": True},
        {"key": "filter[status]", "value": "active", "description": "Enum: pending|active|suspended|rejected|banned", "disabled": True},
        {"key": "filter[email]", "value": "admin@rashid.test", "disabled": True},
        {"key": "filter[roles.name]", "value": "finance", "description": "Role name filter", "disabled": True},
        {"key": "sort", "value": "-created_at", "description": "Allowed: id, full_name, created_at, email (prefix - for desc)", "disabled": True},
        {"key": "per_page", "value": "10", "description": "Default 10"},
        {"key": "page", "value": "1", "disabled": True},
    ]
    original = {"method": "GET", "header": header(), "url": url(path, query)}
    return req(
        "List Users (Auth module)",
        "GET",
        path,
        description=(
            "Paginated user list via User module. "
            "The authenticated user is **excluded** from results."
        ),
        roles=["super-admin"],
        enums="`filter[status]`: pending | active | suspended | rejected | banned\n`filter[roles.name]`: super-admin | finance | inventory",
        query=query,
        responses=[
            example(
                "200 OK",
                200,
                {
                    "success": True,
                    "message": "Users fetched successfully",
                    "data": [SAMPLE_USER],
                    "meta": {"total": 1, "per_page": 10, "current_page": 1, "last_page": 1},
                    "links": {"next": None},
                },
                original_request=original,
            ),
            *std_auth_errors(original),
        ],
    )


def auth_users_create() -> dict:
    path = "auth/users"
    body = {
        "full_name": "Finance Manager",
        "user_name": "finance_user",
        "email": "finance@rashid.test",
        "password": "Password123!",
        "role": "finance",
    }
    original = {"method": "POST", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Create User (Auth module)",
        "POST",
        path,
        description="Create a user and assign a single role.",
        roles=["super-admin"],
        enums="`role`: super-admin | finance | inventory (must exist in `roles` table)",
        body=body,
        json_body=True,
        responses=[
            example("200 OK", 200, ok("User created successfully", {**SAMPLE_USER, "name": "Finance Manager", "user_name": "finance_user", "email": "finance@rashid.test", "role": "finance"}), original_request=original),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def auth_users_update() -> dict:
    path = "auth/users/{{user_uuid}}"
    body = {
        "full_name": "Updated Name",
        "user_name": "updated_user",
        "email": "updated@rashid.test",
        "password": "Password123!",
        "role": "inventory",
    }
    original = {"method": "PATCH", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Update User (Auth module)",
        "PATCH",
        path,
        description="Partial update. `{user}` is UUID.",
        roles=["super-admin"],
        enums="`role` (optional): super-admin | finance | inventory",
        body=body,
        json_body=True,
        responses=[
            example("200 OK", 200, ok("User updated successfully", {**SAMPLE_USER, "name": "Updated Name", "role": "inventory"}), original_request=original),
            *std_auth_errors(original, include_validation=True, include_not_found=True),
        ],
    )


def auth_users_delete() -> dict:
    path = "auth/users/{{user_uuid}}"
    original = {"method": "DELETE", "header": header(), "url": url(path)}
    return req(
        "Delete User (Auth module)",
        "DELETE",
        path,
        description=(
            "Soft-delete user by UUID. "
            "**Cannot delete own account** — returns 403 if `{user}` equals the authenticated UUID."
        ),
        roles=["super-admin"],
        responses=[
            example("200 OK", 200, ok("User deleted successfully"), original_request=original),
            example(
                "403 Cannot delete own account",
                403,
                fail("You cannot delete your own account"),
                original_request=original,
            ),
            *std_auth_errors(original, include_not_found=True),
        ],
    )


# --- Authorization module /users & /roles ---

def roles_list() -> dict:
    path = "roles"
    original = {"method": "GET", "header": header(locale=True), "url": url(path)}
    return req(
        "List Roles",
        "GET",
        path,
        description="Returns all Spatie roles.",
        roles=["super-admin"],
        enums="Role names: super-admin | finance | inventory",
        locale=True,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Roles fetched successfully",
                    [
                        {"id": 1, "name": "super-admin"},
                        {"id": 2, "name": "finance"},
                        {"id": 3, "name": "inventory"},
                    ],
                ),
                original_request=original,
            ),
            *std_auth_errors(original),
        ],
    )


def authz_users_list() -> dict:
    path = "users"
    query = [
        {"key": "search", "value": "", "description": "Search: user_name, email", "disabled": True},
        {"key": "filter[status]", "value": "active", "description": "pending|active|suspended|rejected|banned", "disabled": True},
        {"key": "filter[email]", "value": "", "disabled": True},
        {"key": "filter[roles.name]", "value": "finance", "disabled": True},
        {"key": "sort", "value": "-created_at", "description": "Allowed: id, user_name, created_at", "disabled": True},
        {"key": "per_page", "value": "10"},
        {"key": "page", "value": "1", "disabled": True},
    ]
    original = {"method": "GET", "header": header(locale=True), "url": url(path, query)}
    return req(
        "List Users (Authorization module)",
        "GET",
        path,
        description=(
            "Paginated users (Authorization module). Overlaps with `/auth/users`. "
            "The authenticated user is **excluded** from results."
        ),
        roles=["super-admin"],
        enums="`filter[status]`: pending | active | suspended | rejected | banned",
        query=query,
        locale=True,
        responses=[
            example(
                "200 OK",
                200,
                {
                    "success": True,
                    "message": "Users fetched successfully",
                    "data": [SAMPLE_USER],
                    "meta": {"total": 1, "per_page": 10, "current_page": 1, "last_page": 1},
                    "links": {"next": None},
                },
                original_request=original,
            ),
            *std_auth_errors(original),
        ],
    )


def authz_users_update() -> dict:
    path = "users/{{user_uuid}}"
    body = {
        "name": "Updated Full Name",
        "email": "updated@rashid.test",
        "password": "Password123!",
        "password_confirmation": "Password123!",
        "roles": ["finance"],
    }
    original = {"method": "POST", "header": header(json_body=True, locale=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Update User (Authorization module)",
        "POST",
        path,
        description="Update user. Password requires confirmation and complexity (letters, mixed case, numbers, symbols). `{user}` is UUID.",
        roles=["super-admin"],
        enums="`roles[]`: super-admin | finance | inventory",
        body=body,
        json_body=True,
        locale=True,
        responses=[
            example("200 OK", 200, ok("User updated successfully", SAMPLE_USER), original_request=original),
            *std_auth_errors(original, include_validation=True, include_not_found=True),
        ],
    )


def authz_users_delete() -> dict:
    path = "users/{{user_uuid}}"
    original = {"method": "DELETE", "header": header(locale=True), "url": url(path)}
    return req(
        "Delete User (Authorization module)",
        "DELETE",
        path,
        description=(
            "Soft-delete user by UUID. "
            "**Cannot delete own account** — returns 403 if `{user}` equals the authenticated UUID."
        ),
        roles=["super-admin"],
        locale=True,
        responses=[
            example("200 OK", 200, ok("User deleted successfully"), original_request=original),
            example(
                "403 Cannot delete own account",
                403,
                fail("You cannot delete your own account"),
                original_request=original,
            ),
            *std_auth_errors(original, include_not_found=True),
        ],
    )


def authz_users_status() -> dict:
    path = "users/{{user_uuid}}/status"
    body = {"status": "active"}
    original = {"method": "POST", "header": header(json_body=True, locale=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Update User Status",
        "POST",
        path,
        description="Change user account status.",
        roles=["super-admin"],
        enums="`status`: pending | active | suspended | rejected | banned",
        body=body,
        json_body=True,
        locale=True,
        responses=[
            example("200 OK", 200, ok("User status updated successfully", SAMPLE_USER), original_request=original),
            *std_auth_errors(original, include_validation=True, include_not_found=True),
        ],
    )


# --- Settings ---

def settings_list() -> dict:
    path = "settings"
    original = {"method": "GET", "header": header(auth=False, locale=True), "url": url(path)}
    return req(
        "List Settings",
        "GET",
        path,
        description="Public (no auth). Rate-limited. Locale via `Accept-Language`.",
        roles=["public"],
        enums="Setting `type`: string | integer | boolean | json | decimal\nKnown keys: `admin_fee_percentage`, `total_operational_deduction`",
        locale=True,
        auth_required=False,
        no_auth_header=True,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Settings fetched successfully",
                    [
                        {"key": "admin_fee_percentage", "value": "12", "type": "decimal", "isPublic": True},
                        {"key": "total_operational_deduction", "value": "1235", "type": "decimal", "isPublic": True},
                    ],
                ),
                original_request=original,
            ),
            example("429 Rate limited", 429, RATE_LIMIT, original_request=original),
        ],
    )


def settings_update() -> dict:
    path = "settings/{{setting_key}}"
    body = {"value": 12, "type": "decimal", "is_public": True}
    original = {"method": "POST", "header": header(json_body=True, locale=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Update Setting by Key",
        "POST",
        path,
        description=(
            "Update a single setting. Path `{key}` e.g. `admin_fee_percentage` or `total_operational_deduction`.\n"
            "- admin_fee_percentage: numeric 0–100\n"
            "- total_operational_deduction: numeric > 0\n\n"
            + OP_DEDUCTION_EFFECTIVE_DATE_NOTE
            + "\n\n"
            + ADMIN_FEE_EFFECTIVE_DATE_NOTE
        ),
        roles=["super-admin"],
        enums="`type`: string | integer | boolean | json | decimal",
        body=body,
        json_body=True,
        locale=True,
        responses=[
            example("200 OK", 200, ok("Setting updated successfully", {"admin_fee_percentage": 12}), original_request=original),
            *std_auth_errors(original, include_validation=True),
            example("400 Failed", 400, fail("Failed to update setting", "An unexpected error occurred"), original_request=original),
        ],
    )


def settings_bulk() -> dict:
    path = "settings"
    body = {
        "settings": [
            {"key": "admin_fee_percentage", "value": 12, "type": "decimal", "is_public": True},
            {"key": "total_operational_deduction", "value": 1235, "type": "decimal", "is_public": True},
        ]
    }
    original = {"method": "PUT", "header": header(json_body=True, locale=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Bulk Update Settings",
        "PUT",
        path,
        description=(
            "Update multiple settings at once.\n\n"
            + OP_DEDUCTION_EFFECTIVE_DATE_NOTE
            + "\n\n"
            + ADMIN_FEE_EFFECTIVE_DATE_NOTE
        ),
        roles=["super-admin"],
        enums="`settings.*.type`: string | integer | boolean | json | decimal",
        body=body,
        json_body=True,
        locale=True,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Setting updated successfully",
                    [
                        {"key": "admin_fee_percentage", "value": "12", "type": "decimal", "isPublic": True},
                        {"key": "total_operational_deduction", "value": "1235", "type": "decimal", "isPublic": True},
                    ],
                ),
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


# --- Categories ---

def categories_list() -> dict:
    path = "categories"
    original = {"method": "GET", "header": header(), "url": url(path)}
    return req(
        "List Categories",
        "GET",
        path,
        description="All categories (no pagination/filters).",
        roles=["super-admin", "finance", "inventory"],
        responses=[
            example("200 OK", 200, ok("Categories fetched successfully", [SAMPLE_CATEGORY]), original_request=original),
            *std_auth_errors(original),
        ],
    )


def categories_create() -> dict:
    path = "categories"
    body = {"name": "إغاثة"}
    original = {"method": "POST", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Create Category",
        "POST",
        path,
        description="Arabic name only. Must be unique.",
        roles=["super-admin"],
        body=body,
        json_body=True,
        responses=[
            example("200 OK", 200, ok("Category created successfully", {**SAMPLE_CATEGORY, "id": 2, "name": "إغاثة"}), original_request=original),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def categories_update() -> dict:
    path = "categories/{{category_id}}"
    body = {"name": "صحة محدثة"}
    original = {"method": "PATCH", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Update Category",
        "PATCH",
        path,
        description="Rename category. `{category}` is numeric id.",
        roles=["super-admin"],
        body=body,
        json_body=True,
        responses=[
            example("200 OK", 200, ok("Category updated successfully", {**SAMPLE_CATEGORY, "name": "صحة محدثة"}), original_request=original),
            *std_auth_errors(original, include_validation=True, include_not_found=True),
        ],
    )


def categories_delete() -> dict:
    path = "categories/{{category_id}}"
    original = {"method": "DELETE", "header": header(), "url": url(path)}
    return req(
        "Delete Category",
        "DELETE",
        path,
        description=(
            "Delete category by id.\n"
            "Loads all child projects, validates each with the **same** Project deletion constraints, "
            "then deletes projects via `DeleteProjectWorkflow` before deleting the category "
            "(all-or-nothing transaction).\n"
            "If any project is blocked (e.g. daily journal entries), returns 422 and deletes nothing. "
            "DB FK is `ON DELETE RESTRICT` (no cascade bypass)."
        ),
        roles=["super-admin"],
        responses=[
            example("200 OK", 200, ok("Category deleted successfully"), original_request=original),
            example(
                "422 Project deletion blocked",
                422,
                {
                    "success": False,
                    "message": "Project cannot be deleted because it has daily journal entries.",
                },
                original_request=original,
            ),
            *std_auth_errors(original, include_not_found=True),
        ],
    )


# --- Projects ---

PROJECT_ENUMS = (
    "`fund_type`: fixed | variable\n"
    "`status`: active | stopped | archived\n"
    "`operational_deduction_type`: relative | fixed | exempt\n"
    "`administrative_exempt`: boolean\n"
    "When `operational_deduction_type=fixed`, `operational_fixed_amount` is required (numeric > 0)."
)

LIST_PROJECT_QUERY = [
    {"key": "tab", "value": "fixed", "description": "Enum: fixed | variable | archived", "disabled": True},
    {"key": "status", "value": "active", "description": "Enum: active | stopped | archived (ignored when tab=archived)", "disabled": True},
    {"key": "fund_type", "value": "fixed", "description": "Enum: fixed | variable (ignored when tab is set)", "disabled": True},
    {"key": "category_id", "value": "1", "description": "Must exist in categories", "disabled": True},
    {"key": "created_from", "value": "2026-01-01", "description": "Date Y-m-d", "disabled": True},
    {"key": "created_to", "value": "2026-12-31", "description": "Date Y-m-d, >= created_from", "disabled": True},
    {"key": "search", "value": "", "description": "Search project name", "disabled": True},
    {"key": "filter[status]", "value": "active", "description": "BaseQueryService filter", "disabled": True},
    {"key": "filter[fund_type]", "value": "fixed", "disabled": True},
    {"key": "filter[category_id]", "value": "1", "disabled": True},
    {"key": "sort", "value": "-created_at", "description": "Allowed: name, created_at, status, fund_type", "disabled": True},
    {"key": "per_page", "value": "15", "description": "1–100, default 15"},
    {"key": "page", "value": "1", "disabled": True},
]


def projects_list() -> dict:
    path = "projects"
    original = {"method": "GET", "header": header(), "url": url(path, LIST_PROJECT_QUERY)}
    return req(
        "List Projects",
        "GET",
        path,
        description="Paginated projects with tabs, filters, sort, and search.",
        roles=["super-admin", "finance", "inventory"],
        enums=(
            "`tab`: fixed | variable | archived\n"
            "`status` / `filter[status]`: active | stopped | archived\n"
            "`fund_type` / `filter[fund_type]`: fixed | variable\n"
            "`sort`: name | created_at | status | fund_type (prefix `-` for desc)"
        ),
        query=LIST_PROJECT_QUERY,
        responses=[
            example(
                "200 OK",
                200,
                {
                    "success": True,
                    "message": "Projects fetched successfully",
                    "data": [SAMPLE_PROJECT],
                    "meta": {"total": 1, "per_page": 15, "current_page": 1, "last_page": 1},
                    "links": {"next": None},
                },
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def projects_show() -> dict:
    path = "projects/{{project_id}}"
    original = {"method": "GET", "header": header(), "url": url(path)}
    return req(
        "Show Project",
        "GET",
        path,
        description="Fetch a single project by numeric id.",
        roles=["super-admin", "finance", "inventory"],
        responses=[
            example("200 OK", 200, ok("Project fetched successfully", SAMPLE_PROJECT), original_request=original),
            *std_auth_errors(original, include_not_found=True),
        ],
    )


def projects_store() -> dict:
    path = "projects"
    body = {
        "name": "مشروع جديد",
        "category_id": 1,
        "fund_type": "fixed",
        "status": "active",
        "operational_deduction_type": "fixed",
        "operational_fixed_amount": 154,
        "administrative_exempt": False,
    }
    original = {"method": "POST", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Create Project",
        "POST",
        path,
        description="Create project. Name unique. `operational_fixed_amount` required when type is `fixed`.",
        roles=["super-admin"],
        enums=PROJECT_ENUMS,
        body=body,
        json_body=True,
        responses=[
            example("200 OK", 200, ok("Project created successfully", SAMPLE_PROJECT), original_request=original),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def projects_update() -> dict:
    path = "projects/{{project_id}}"
    body = {
        "name": "مشروع محدث",
        "category_id": 1,
        "fund_type": "variable",
        "status": "stopped",
        "operational_deduction_type": "relative",
        "operational_fixed_amount": None,
        "administrative_exempt": True,
    }
    original = {"method": "PATCH", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Update Project",
        "PATCH",
        path,
        description="Full update of project fields.",
        roles=["super-admin"],
        enums=PROJECT_ENUMS,
        body=body,
        json_body=True,
        responses=[
            example("200 OK", 200, ok("Project updated successfully", {**SAMPLE_PROJECT, "name": "مشروع محدث", "fund_type": "variable", "status": "stopped", "operational_deduction_type": "relative", "administrative_exempt": True}), original_request=original),
            *std_auth_errors(original, include_validation=True, include_not_found=True),
        ],
    )


def projects_delete() -> dict:
    path = "projects/{{project_id}}"
    original = {"method": "DELETE", "header": header(), "url": url(path)}
    return req(
        "Delete Project",
        "DELETE",
        path,
        description="Hard delete. Blocked by deletion constraints (e.g. journal entries) → BusinessException 422.",
        roles=["super-admin"],
        responses=[
            example("200 OK", 200, ok("Project deleted successfully"), original_request=original),
            example(
                "422 Business rule blocked",
                422,
                {"success": False, "message": "Project cannot be deleted because it has daily journal entries."},
                original_request=original,
            ),
            *std_auth_errors(original, include_not_found=True),
        ],
    )


def projects_archive() -> dict:
    path = "projects/{{project_id}}/archive"
    original = {"method": "POST", "header": header(), "url": url(path)}
    return req(
        "Archive Project",
        "POST",
        path,
        description="Sets status to archived.",
        roles=["super-admin"],
        enums="Resulting `status`: archived",
        responses=[
            example("200 OK", 200, ok("Project archived successfully", {**SAMPLE_PROJECT, "status": "archived", "archived_at": "2026-07-28 15:00:00"}), original_request=original),
            *std_auth_errors(original, include_not_found=True),
        ],
    )


def projects_restore() -> dict:
    path = "projects/{{project_id}}/restore"
    original = {"method": "POST", "header": header(), "url": url(path)}
    return req(
        "Restore Project",
        "POST",
        path,
        description="Restore archived project to active.",
        roles=["super-admin"],
        enums="Resulting `status`: active",
        responses=[
            example("200 OK", 200, ok("Project restored successfully", SAMPLE_PROJECT), original_request=original),
            *std_auth_errors(original, include_not_found=True),
        ],
    )


def financial_settings_show() -> dict:
    path = "projects/financial-settings"
    original = {"method": "GET", "header": header(), "url": url(path)}
    return req(
        "Show Project Financial Settings",
        "GET",
        path,
        description=(
            "Global financial settings used by projects/journals "
            "(`admin_fee_percentage`, `total_operational_deduction`).\n"
            "Configured values here may differ from today's effective journal rates until the next calendar day.\n\n"
            + OP_DEDUCTION_EFFECTIVE_DATE_NOTE
            + "\n\n"
            + ADMIN_FEE_EFFECTIVE_DATE_NOTE
        ),
        roles=["super-admin", "finance"],
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Settings fetched successfully",
                    {"admin_fee_percentage": 12.0, "total_operational_deduction": 1235.0},
                ),
                original_request=original,
            ),
            *std_auth_errors(original),
        ],
    )


def financial_settings_update() -> dict:
    path = "projects/financial-settings"
    body = {"admin_fee_percentage": 12, "total_operational_deduction": 1235}
    original = {"method": "PATCH", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Update Project Financial Settings",
        "PATCH",
        path,
        description=(
            "At least one of the two fields required.\n"
            "- admin_fee_percentage: 0–100\n"
            "- total_operational_deduction: > 0\n\n"
            + OP_DEDUCTION_EFFECTIVE_DATE_NOTE
            + "\n\n"
            + ADMIN_FEE_EFFECTIVE_DATE_NOTE
        ),
        roles=["super-admin"],
        body=body,
        json_body=True,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Setting updated successfully",
                    {"admin_fee_percentage": 12.0, "total_operational_deduction": 1235.0},
                ),
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def calculate_deductions() -> dict:
    path = "projects/calculate-deductions"
    body = {"incomes": {"1": 1000, "2": 5000, "3": 900}}
    original = {"method": "POST", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Calculate Deductions (bulk)",
        "POST",
        path,
        description=(
            "`incomes` is a map of `project_id => income` for active projects.\n"
            "Operational pool for this preview uses **today's effective** rate "
            "(not a mid-day configured value that starts tomorrow).\n\n"
            + OP_DEDUCTION_EFFECTIVE_DATE_NOTE
        ),
        roles=["super-admin", "finance"],
        enums="`operational_deduction_type` in response: relative | fixed | exempt",
        body=body,
        json_body=True,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Deductions calculated successfully",
                    [
                        {
                            "project_id": 1,
                            "project_name": "Relative Project",
                            "income": 1000,
                            "operational_deduction_type": "relative",
                            "operational_deduction": 1081,
                            "administrative_deduction": 120,
                            "administrative_exempt": False,
                        }
                    ],
                ),
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def calculate_project_deduction() -> dict:
    path = "projects/{{project_id}}/calculate-deduction"
    body = {"income": 1000, "relative_incomes": {"1": 1000, "2": 500}}
    original = {"method": "POST", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Calculate Project Deduction",
        "POST",
        path,
        description=(
            "Single-project deduction. Optional `relative_incomes` map for relative pool participation.\n"
            "Uses **today's effective** operational deduction pool.\n\n"
            + OP_DEDUCTION_EFFECTIVE_DATE_NOTE
        ),
        roles=["super-admin", "finance"],
        enums="`operational_deduction_type` in response: relative | fixed | exempt",
        body=body,
        json_body=True,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Deductions calculated successfully",
                    {
                        "project_id": 1,
                        "project_name": "مشروع صحي ثابت",
                        "income": 1000,
                        "operational_deduction_type": "fixed",
                        "operational_deduction": 154,
                        "administrative_deduction": 120,
                        "administrative_exempt": False,
                    },
                ),
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True, include_not_found=True),
        ],
    )


# --- Daily Journal ---

JOURNAL_ENUMS = (
    "`journal_date`: Y-m-d (optional; defaults to today)\n"
    "Only **active** projects allowed in entries.\n"
    "`daily_income`, `daily_expense`, `contribution`: nullable|numeric|min:0.\n"
    "Calculated fields are **rejected** if sent: administrative_expense, administrative_fee, "
    "operational_deduction, daily_total, fund_balance, accumulated_administrative_debt, administrative_debt\n\n"
    "### Operational deduction pool\n"
    "`operational_deduction` is computed from the **effective** pool for `journal_date` "
    "(not the live configured settings value if it was changed mid-day for a later effective date).\n\n"
    "### Daily Contribution rules\n"
    "A **positive** `contribution` is validated after the pipeline runs once without it:\n"
    "- Requester must have the **super-admin** role.\n"
    "- The project must have a **real remaining deficit** (negative fund balance before it is clamped to 0).\n"
    "- The contribution must **not exceed** that remaining deficit.\n"
    "A `null` or `0` contribution bypasses these checks (any journal editor may send it).\n"
    "Contribution never recalculates administrative_fee, operational_deduction, or administrative_expense; "
    "it only feeds into daily_total (income + contribution - expense - admin_expense - admin_fee - op_deduction)."
)


def journal_show() -> dict:
    path = "daily-journals"
    query = [
        {"key": "journal_date", "value": "2026-07-28", "description": "Y-m-d; omit for today", "disabled": False},
    ]
    original = {"method": "GET", "header": header(), "url": url(path, query)}
    return req(
        "Show Daily Journal",
        "GET",
        path,
        description="Fetch journal for a date (ensures entries for active projects).",
        roles=["super-admin", "finance"],
        enums=JOURNAL_ENUMS,
        query=query,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Daily journal fetched successfully",
                    {"journal_date": "2026-07-28", "entries": [SAMPLE_JOURNAL_ENTRY]},
                ),
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def journal_save() -> dict:
    path = "daily-journals"
    body = {
        "journal_date": "2026-07-28",
        "entries": [
            {"project_id": 1, "daily_income": 1000, "daily_expense": 100, "contribution": 0},
            {"project_id": 2, "daily_income": 500, "daily_expense": 0},
        ],
    }
    original = {"method": "PUT", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    contribution_body = {
        "journal_date": "2026-07-28",
        "entries": [
            {"project_id": 1, "daily_income": 0, "daily_expense": 200, "contribution": 150},
        ],
    }
    contribution_original = {
        "method": "PUT",
        "header": header(json_body=True),
        "body": body_raw(contribution_body),
        "url": url(path),
    }
    return req(
        "Save Daily Journal (PUT)",
        "PUT",
        path,
        description="Upsert journal entries and recalculate derived fields.",
        roles=["super-admin", "finance"],
        enums=JOURNAL_ENUMS,
        body=body,
        json_body=True,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Daily journal saved successfully",
                    {"journal_date": "2026-07-28", "entries": [SAMPLE_JOURNAL_ENTRY]},
                ),
                original_request=original,
            ),
            example(
                "200 Super-admin contribution covers deficit",
                200,
                ok(
                    "Daily journal saved successfully",
                    {"journal_date": "2026-07-28", "entries": [SAMPLE_JOURNAL_ENTRY_CONTRIBUTION]},
                ),
                original_request=contribution_original,
            ),
            example(
                "422 Calculated field not allowed",
                422,
                {
                    "message": "The given data was invalid.",
                    "errors": {
                        "entries.0.administrative_fee": [
                            "The administrative_fee field is calculated and cannot be modified."
                        ]
                    },
                },
                original_request=original,
            ),
            example(
                "422 Contribution requires super-admin",
                422,
                {
                    "message": "Only a super admin can save a daily contribution.",
                    "errors": {
                        "entries.0.contribution": [
                            "Only a super admin can save a daily contribution."
                        ]
                    },
                },
                original_request=contribution_original,
            ),
            example(
                "422 Contribution without a deficit",
                422,
                {
                    "message": "A contribution can only be saved when the project has a remaining deficit.",
                    "errors": {
                        "entries.0.contribution": [
                            "A contribution can only be saved when the project has a remaining deficit."
                        ]
                    },
                },
                original_request=contribution_original,
            ),
            example(
                "422 Contribution exceeds remaining deficit",
                422,
                {
                    "message": "The contribution may not exceed the project's remaining deficit.",
                    "errors": {
                        "entries.0.contribution": [
                            "The contribution may not exceed the project's remaining deficit."
                        ]
                    },
                },
                original_request=contribution_original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def journal_update() -> dict:
    path = "daily-journals"
    body = {
        "journal_date": "2026-07-28",
        "entries": [
            {"project_id": 1, "daily_income": 1200, "daily_expense": 150}
        ],
    }
    original = {"method": "PATCH", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    contribution_body = {
        "journal_date": "2026-07-28",
        "entries": [
            {"project_id": 1, "contribution": 150}
        ],
    }
    contribution_original = {
        "method": "PATCH",
        "header": header(json_body=True),
        "body": body_raw(contribution_body),
        "url": url(path),
    }
    return req(
        "Update Daily Journal (PATCH)",
        "PATCH",
        path,
        description=(
            "Same body rules as Save (extends SaveDailyJournalRequest). "
            "Only the fields you send are updated; omitted fields keep their stored values. "
            "A super-admin may add a `contribution` here once an existing entry has a remaining deficit."
        ),
        roles=["super-admin", "finance"],
        enums=JOURNAL_ENUMS,
        body=body,
        json_body=True,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Daily journal updated successfully",
                    {"journal_date": "2026-07-28", "entries": [SAMPLE_JOURNAL_ENTRY]},
                ),
                original_request=original,
            ),
            example(
                "200 Super-admin contribution covers deficit",
                200,
                ok(
                    "Daily journal updated successfully",
                    {"journal_date": "2026-07-28", "entries": [SAMPLE_JOURNAL_ENTRY_CONTRIBUTION]},
                ),
                original_request=contribution_original,
            ),
            example(
                "422 Contribution requires super-admin",
                422,
                {
                    "message": "Only a super admin can save a daily contribution.",
                    "errors": {
                        "entries.0.contribution": [
                            "Only a super admin can save a daily contribution."
                        ]
                    },
                },
                original_request=contribution_original,
            ),
            example(
                "422 Contribution exceeds remaining deficit",
                422,
                {
                    "message": "The contribution may not exceed the project's remaining deficit.",
                    "errors": {
                        "entries.0.contribution": [
                            "The contribution may not exceed the project's remaining deficit."
                        ]
                    },
                },
                original_request=contribution_original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def journal_repay_debt() -> dict:
    path = "daily-journals/repay-debt"
    body = {
        "journal_date": "2026-07-28",
        "project_id": 1,
    }
    original = {"method": "POST", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Repay Administrative Debt",
        "POST",
        path,
        description=(
            "User-initiated repayment only. Body: `journal_date` + `project_id` (no amount). "
            "Consumes available fund_balance surplus automatically in order: "
            "1) cover remaining fund deficit if any, "
            "2) repay today's administrative_debt, "
            "3) repay accumulated_administrative_debt. "
            "Rejects when the entry has no positive surplus. Does not recalculate fees/deductions."
        ),
        roles=["super-admin", "finance"],
        enums="`journal_date`: Y-m-d (required). `project_id`: active project id (required). No amount field.",
        body=body,
        json_body=True,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Administrative debt repaid successfully.",
                    {
                        **SAMPLE_JOURNAL_ENTRY,
                        "fund_balance": "50.00",
                        "administrative_debt": "0.00",
                        "accumulated_administrative_debt": "0.00",
                    },
                ),
                original_request=original,
            ),
            example(
                "422 No surplus available",
                422,
                {
                    "message": "The given data was invalid.",
                    "errors": {
                        "project_id": [
                            "Administrative debt can only be repaid when the project has available surplus."
                        ]
                    },
                },
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


# --- Inventory ---

INVENTORY_ROLES = ["super-admin", "inventory"]

INVENTORY_ITEM_ENUMS = (
    "`name`, `unit`: required strings\n"
    "`category_id`: required, must exist in inventory_categories (not project categories)\n"
    "`project_id`: required, must exist in projects\n"
    "`opening_price`, `opening_quantity`: required numeric ≥ 0 (creates opening batch + incoming movement)\n"
    "`minimum_stock_level`: nullable numeric ≥ 0\n"
    "`notes`: nullable string\n"
    "Prohibited (system-calculated): `code`, `current_balance`, `total_incoming_quantity`, "
    "`total_outgoing_quantity`, `latest_incoming_price`"
)

INVENTORY_INCOMING_ENUMS = (
    "`inventory_item_id`: required, exists in inventory_items\n"
    "`quantity`: required numeric > 0\n"
    "`unit_price`: required numeric ≥ 0\n"
    "`notes`: nullable string\n"
    "Prohibited (system-set): `movement_date` (today), `total_cost` (quantity × unit_price)"
)

INVENTORY_OUTGOING_ENUMS = (
    "`inventory_item_id`: required, exists in inventory_items\n"
    "`quantity`: required numeric > 0 (FIFO costed against batches)\n"
    "`beneficiary_project_id`: required, exists in projects\n"
    "`expense_type`: required enum `operational` | `administrative`\n"
    "`notes`: nullable string\n"
    "Prohibited (system-set): `movement_date`, `total_cost`, `unit_price` "
    "(server sets `unit_price` = FIFO weighted average `total_cost / quantity`)\n\n"
    "### Journal link\n"
    "Outgoing with `expense_type=administrative` feeds Daily Journal "
    "`administrative_expense` for the **beneficiary** project on `movement_date` "
    "(sum of persisted FIFO `total_cost`). Operational outgoings do not."
)

LIST_INVENTORY_ITEMS_QUERY = [
    {"key": "search", "value": "قفازات", "description": "Search name, code", "disabled": True},
    {"key": "filter[category_id]", "value": "1", "description": "Inventory category id", "disabled": True},
    {"key": "filter[project_id]", "value": "1", "description": "Owning project id", "disabled": True},
    {"key": "sort", "value": "-created_at", "description": "name | code | inventory_category_id | created_at | current_balance", "disabled": True},
    {"key": "per_page", "value": "15", "description": "1–100, default 15"},
    {"key": "page", "value": "1", "disabled": True},
]

LIST_INVENTORY_MOVEMENTS_QUERY = [
    {"key": "filter[inventory_item_id]", "value": "1", "disabled": True},
    {"key": "filter[type]", "value": "outgoing", "description": "incoming | outgoing", "disabled": True},
    {"key": "filter[expense_type]", "value": "administrative", "description": "operational | administrative", "disabled": True},
    {"key": "filter[beneficiary_project_id]", "value": "1", "disabled": True},
    {"key": "sort", "value": "-created_at", "description": "movement_date | created_at | quantity", "disabled": True},
    {"key": "per_page", "value": "15", "description": "1–100, default 15"},
    {"key": "page", "value": "1", "disabled": True},
]


def inventory_items_list() -> dict:
    path = "inventory/items"
    original = {"method": "GET", "header": header(), "url": url(path, LIST_INVENTORY_ITEMS_QUERY)}
    return req(
        "List Inventory Items",
        "GET",
        path,
        description="Paginated inventory items with filters, sort, and search.",
        roles=INVENTORY_ROLES,
        enums=(
            "`filter[category_id]`: inventory category id\n"
            "`filter[project_id]`: integer\n"
            "`search`: name | code\n"
            "`sort`: name | code | inventory_category_id | created_at | current_balance (prefix `-` for desc)"
        ),
        query=LIST_INVENTORY_ITEMS_QUERY,
        responses=[
            example(
                "200 OK",
                200,
                {
                    "success": True,
                    "message": "Inventory items fetched successfully.",
                    "data": [SAMPLE_INVENTORY_ITEM],
                    "meta": {"total": 1, "per_page": 15, "current_page": 1, "last_page": 1},
                    "links": {"next": None},
                },
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def inventory_items_show() -> dict:
    path = "inventory/items/{{inventory_item_id}}"
    original = {"method": "GET", "header": header(), "url": url(path)}
    return req(
        "Show Inventory Item",
        "GET",
        path,
        description="Fetch a single inventory item by id (includes owning project).",
        roles=INVENTORY_ROLES,
        responses=[
            example(
                "200 OK",
                200,
                ok("Inventory item fetched successfully.", SAMPLE_INVENTORY_ITEM),
                original_request=original,
            ),
            example(
                "404 Not Found",
                404,
                fail("Inventory item not found."),
                original_request=original,
            ),
            *std_auth_errors(original),
        ],
    )


def inventory_items_create() -> dict:
    path = "inventory/items"
    body = {
        "name": "قفازات طبية",
        "category_id": 1,
        "project_id": 1,
        "unit": "علبة",
        "opening_price": 25.5,
        "opening_quantity": 100,
        "minimum_stock_level": 10,
        "notes": "مخزون افتتاحي",
    }
    original = {"method": "POST", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Create Inventory Item",
        "POST",
        path,
        description=(
            "Create an inventory item. System generates `code`. "
            "Opening quantity/price create an opening batch and incoming movement. "
            "`movement_date` is always today."
        ),
        roles=INVENTORY_ROLES,
        enums=INVENTORY_ITEM_ENUMS,
        body=body,
        json_body=True,
        responses=[
            example(
                "201 Created",
                201,
                ok("Inventory item created successfully.", SAMPLE_INVENTORY_ITEM),
                original_request=original,
            ),
            example(
                "422 Calculated field not allowed",
                422,
                {
                    "message": "The given data was invalid.",
                    "errors": {
                        "code": ["The code field is prohibited."],
                        "current_balance": ["The current balance field is prohibited."],
                    },
                },
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def inventory_movements_list() -> dict:
    path = "inventory/movements"
    original = {"method": "GET", "header": header(), "url": url(path, LIST_INVENTORY_MOVEMENTS_QUERY)}
    return req(
        "List Inventory Movements",
        "GET",
        path,
        description="Paginated stock movements (incoming/outgoing) with FIFO consumptions when loaded.",
        roles=INVENTORY_ROLES,
        enums=(
            "`filter[type]`: incoming | outgoing\n"
            "`filter[expense_type]`: operational | administrative\n"
            "`filter[inventory_item_id]`, `filter[beneficiary_project_id]`: integers\n"
            "`sort`: movement_date | created_at | quantity (prefix `-` for desc)"
        ),
        query=LIST_INVENTORY_MOVEMENTS_QUERY,
        responses=[
            example(
                "200 OK",
                200,
                {
                    "success": True,
                    "message": "Inventory movements fetched successfully.",
                    "data": [SAMPLE_INVENTORY_MOVEMENT_IN, SAMPLE_INVENTORY_MOVEMENT_OUT],
                    "meta": {"total": 2, "per_page": 15, "current_page": 1, "last_page": 1},
                    "links": {"next": None},
                },
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def inventory_incoming_create() -> dict:
    path = "inventory/movements/incoming"
    body = {
        "inventory_item_id": 1,
        "quantity": 50,
        "unit_price": 25.5,
        "notes": "شراء جديد",
    }
    original = {"method": "POST", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Create Incoming Stock",
        "POST",
        path,
        description=(
            "Receive stock into an item. Creates a FIFO batch and updates item balances / latest price. "
            "`movement_date` is today; `total_cost` is calculated."
        ),
        roles=INVENTORY_ROLES,
        enums=INVENTORY_INCOMING_ENUMS,
        body=body,
        json_body=True,
        responses=[
            example(
                "201 Created",
                201,
                ok("Incoming stock movement created successfully.", SAMPLE_INVENTORY_MOVEMENT_IN),
                original_request=original,
            ),
            example(
                "404 Item not found",
                404,
                fail("Inventory item not found."),
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def inventory_outgoing_create() -> dict:
    path = "inventory/movements/outgoing"
    body = {
        "inventory_item_id": 1,
        "quantity": 20,
        "beneficiary_project_id": 1,
        "expense_type": "administrative",
        "notes": "صرف إداري",
    }
    original = {"method": "POST", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Create Outgoing Stock",
        "POST",
        path,
        description=(
            "Issue stock to a beneficiary project using FIFO costing. "
            "Rejects when quantity exceeds available balance. "
            "`administrative` expense rolls into Daily Journal administrative_expense for the beneficiary on that date."
        ),
        roles=INVENTORY_ROLES,
        enums=INVENTORY_OUTGOING_ENUMS,
        body=body,
        json_body=True,
        responses=[
            example(
                "201 Created",
                201,
                ok("Outgoing stock movement created successfully.", SAMPLE_INVENTORY_MOVEMENT_OUT),
                original_request=original,
            ),
            example(
                "422 Insufficient stock",
                422,
                fail("Requested quantity exceeds available inventory stock."),
                original_request=original,
            ),
            example(
                "422 Beneficiary project not found",
                422,
                fail("The beneficiary project was not found."),
                original_request=original,
            ),
            example(
                "404 Item not found",
                404,
                fail("Inventory item not found."),
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def inventory_categories_list() -> dict:
    path = "inventory/categories"
    original = {"method": "GET", "header": header(), "url": url(path)}
    return req(
        "List Inventory Categories",
        "GET",
        path,
        description="List inventory categories (separate from project categories).",
        roles=INVENTORY_ROLES,
        responses=[
            example(
                "200 OK",
                200,
                ok("Inventory categories fetched successfully.", [SAMPLE_INVENTORY_CATEGORY]),
                original_request=original,
            ),
            *std_auth_errors(original),
        ],
    )


def inventory_categories_create() -> dict:
    path = "inventory/categories"
    body = {"name": "مستهلكات"}
    original = {"method": "POST", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Create Inventory Category",
        "POST",
        path,
        description="Create an inventory category. Name must be unique in inventory_categories.",
        roles=INVENTORY_ROLES,
        enums="`name`: required string, unique in inventory_categories",
        body=body,
        json_body=True,
        responses=[
            example(
                "201 Created",
                201,
                ok("Inventory category created successfully.", SAMPLE_INVENTORY_CATEGORY),
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def inventory_categories_update() -> dict:
    path = "inventory/categories/{{inventory_category_id}}"
    body = {"name": "معدات"}
    original = {"method": "PATCH", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Update Inventory Category",
        "PATCH",
        path,
        description="Rename an inventory category.",
        roles=INVENTORY_ROLES,
        enums="`name`: required string, unique in inventory_categories",
        body=body,
        json_body=True,
        responses=[
            example(
                "200 OK",
                200,
                ok("Inventory category updated successfully.", {**SAMPLE_INVENTORY_CATEGORY, "name": "معدات"}),
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def inventory_categories_delete() -> dict:
    path = "inventory/categories/{{inventory_category_id}}"
    original = {"method": "DELETE", "header": header(), "url": url(path)}
    return req(
        "Delete Inventory Category",
        "DELETE",
        path,
        description="Delete an inventory category. Blocked when items still reference it.",
        roles=INVENTORY_ROLES,
        responses=[
            example(
                "200 OK",
                200,
                ok("Inventory category deleted successfully."),
                original_request=original,
            ),
            example(
                "422 Has Items",
                422,
                fail("Cannot delete inventory category because it has linked inventory items."),
                original_request=original,
            ),
            *std_auth_errors(original),
        ],
    )


def inventory_folder_items() -> list:
    return [
        inventory_categories_list(),
        inventory_categories_create(),
        inventory_categories_update(),
        inventory_categories_delete(),
        inventory_items_list(),
        inventory_items_create(),
        inventory_items_show(),
        inventory_movements_list(),
        inventory_incoming_create(),
        inventory_outgoing_create(),
    ]


# --- Administration Rates ---

SAMPLE_ADMIN_RATES_DAILY = {

    "date": "2026-07-15",
    "total_income": "1000.00",
    "administrative_percentage": "120.00",
    "administrative_debt": "50.00",
}

ADMIN_RATES_ENUMS = (
    "`month`: integer 1–12 (required)\n"
    "`year`: integer 2000–2100 (required)\n\n"
    "Read-only endpoint. Returns persisted Daily Journal data for non-exempt projects only.\n"
    "Exempt projects are excluded from all totals.\n"
    "`administrative_percentage` reflects the live global setting (default 12%)."
)


def administration_rates_show() -> dict:
    path = "administration-rates"
    query = [
        {"key": "month", "value": "7", "description": "Required. Month 1-12"},
        {"key": "year", "value": "2026", "description": "Required. Year 2000-2100"},
    ]
    original = {"method": "GET", "header": header(), "url": url(path, query)}
    return req(
        "Show Administration Rates",
        "GET",
        path,
        description="Read-only aggregation of administrative fees, income, and debt for non-exempt projects.",
        roles=["super-admin", "finance"],
        enums=ADMIN_RATES_ENUMS,
        query=query,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Administration rates fetched successfully.",
                    {
                        "administrative_percentage": 12,
                        "summary": {
                            "total_institution_income": "5000.00",
                            "total_administrative_percentage": "600.00",
                            "total_administrative_debt": "150.00",
                        },
                        "month": {"month": 7, "year": 2026},
                        "daily_records": [SAMPLE_ADMIN_RATES_DAILY],
                        "monthly_totals": {
                            "month_total_income": "3000.00",
                            "month_total_administrative_percentage": "360.00",
                            "month_total_administrative_debt": "100.00",
                        },
                    },
                ),
                original_request=original,
            ),
            example(
                "422 Validation Error",
                422,
                {
                    "message": "The given data was invalid.",
                    "errors": {
                        "month": ["The month field is required."],
                        "year": ["The year field is required."],
                    },
                },
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


# --- Cash Station ---

SAMPLE_CASH_STATION_PROJECT = {
    "project_id": 1,
    "project_name": "تكية اطعام",
    "previous_monthly_total": "0.00",
    "monthly_total": "1000.00",
    "administrative_debt": "40.00",
    "added_contribution": "0.00",
    "deducted_contribution": "400.00",
    "net_cash_fund": "600.00",
    "remaining_administrative_debt": "40.00",
    "status": None,
}

SAMPLE_CASH_STATION_PAYLOAD = {
    "month": {"month": 7, "year": 2026},
    "carried_forward_from_previous": False,
    "summary": {
        "total_monthly_surplus": "1000.00",
        "total_monthly_deficit": "500.00",
        "administrative_debts": "40.00",
        "net_cash_funds": "500.00",
        "monthly_revenue": "1500.00",
        "monthly_expenses": "300.00",
        "total_administrative_percentage": "140.00",
        "total_operational_deduction": "60.00",
        "net_month": "500.00",
    },
    "projects": [SAMPLE_CASH_STATION_PROJECT],
    "settlements": [
        {
            "id": 1,
            "year": 2026,
            "month": 7,
            "from_project_id": 1,
            "to_project_id": 2,
            "amount": "400.00",
        }
    ],
}

CASH_STATION_MONTH_ENUMS = (
    "`month`: integer 1–12 (required)\n"
    "`year`: integer 2000–2100 (required)\n\n"
    "Monthly Total = SUM(daily_income) − collected administrative percentage − SUM(operational_deduction) − SUM(daily_expense).\n"
    "Collected administrative percentage (non-exempt only) = SUM(administrative_fee − administrative_debt + contribution).\n"
    "Unpaid administrative percentage remains Administrative Debt only and is never deducted from Monthly Total / Net Monthly Result.\n"
    "Previous Monthly Total is 0 until the prior month is explicitly carried forward.\n"
    "After carry-forward, Previous = live Monthly Total of the prior month (not Net Cash Fund).\n"
    "Net Cash Fund = Previous Monthly Total + Monthly Total + Added Contribution − Deducted Contribution.\n"
    "summary.total_monthly_surplus = Σ positive Monthly Totals (before settlements / carry-forward).\n"
    "summary.total_monthly_deficit = Σ |negative Monthly Totals| (before settlements / carry-forward).\n"
    "Settlements do not change surplus, deficit, or net_month; they only move Added/Deducted Contribution and Net Cash Fund.\n"
    "summary.total_administrative_percentage = same collected intake used in Monthly Total.\n"
    "Settlements never enter next month's opening. Net Cash Fund is never carried.\n"
    "Does not recalculate Daily Journal math; reuses persisted journal fields only.\n"
    "`remaining_administrative_debt` = month-end journal `accumulated_administrative_debt` "
    "(already reduced when ADS settles; do not also subtract settlement rows).\n"
    "`administrative_debt` (Cash Station row) = remaining + debt allocated by ADS in this month "
    "(display of pre-settlement month debt).\n"
    "`status` = Cash Box Status from Net Cash Fund: `surplus` | `deficit` | `balanced`."
)


def cash_station_show() -> dict:
    path = "cash-station"
    query = [
        {"key": "month", "value": "7", "description": "Required. Month 1-12"},
        {"key": "year", "value": "2026", "description": "Required. Year 2000-2100"},
    ]
    original = {"method": "GET", "header": header(), "url": url(path, query)}
    return req(
        "Show Cash Station",
        "GET",
        path,
        description=(
            "Monthly financial monitoring for all active projects: summary cards, project rows, "
            "and settlement records for the selected month. Opening balance requires an explicit "
            "carry-forward of the prior month; carried amounts are always live-recomputed from DJ."
        ),
        roles=["super-admin", "finance"],
        enums=CASH_STATION_MONTH_ENUMS,
        query=query,
        responses=[
            example(
                "200 OK",
                200,
                ok("Cash station fetched successfully.", SAMPLE_CASH_STATION_PAYLOAD),
                original_request=original,
            ),
            example(
                "422 Validation Error",
                422,
                {
                    "message": "The given data was invalid.",
                    "errors": {
                        "month": ["The month field is required."],
                        "year": ["The year field is required."],
                    },
                },
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def cash_station_carry_forward() -> dict:
    path = "cash-station/carry-forward"
    body = {"month": 7, "year": 2026}
    original = {"method": "POST", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    carried_payload = {
        **SAMPLE_CASH_STATION_PAYLOAD,
        "month": {"month": 8, "year": 2026},
        "carried_forward_from_previous": True,
        "projects": [
            {
                **SAMPLE_CASH_STATION_PROJECT,
                "previous_monthly_total": "1000.00",
                "monthly_total": "250.00",
                "added_contribution": "0.00",
                "deducted_contribution": "0.00",
                "net_cash_fund": "1250.00",
            }
        ],
        "settlements": [],
    }
    return req(
        "Carry Forward Month",
        "POST",
        path,
        description=(
            "Explicit carry-forward of the source month's Monthly Total into the next month's "
            "Previous Monthly Total. Idempotent. Does **not** close the month or carry Net Cash Fund. "
            "Response returns the **target** (next) month Cash Station payload."
        ),
        roles=["super-admin", "finance"],
        enums=(
            "`month` / `year`: source month being carried (required).\n"
            "Only Monthly Total is carried. Net Cash Fund is never carried."
        ),
        body=body,
        json_body=True,
        responses=[
            example(
                "200 OK",
                200,
                ok("Cash station month carried forward successfully.", carried_payload),
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def cash_station_settlement_create() -> dict:
    path = "cash-station/settlements"
    body = {
        "month": 7,
        "year": 2026,
        "from_project_id": 1,
        "to_project_id": 2,
        "amount": 400,
    }
    original = {"method": "POST", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Create Settlement",
        "POST",
        path,
        description=(
            "Create a surplus→deficit settlement transfer for a month. "
            "Affects Added/Deducted Contribution and Net Cash Fund only; never Monthly Total or Previous. "
            "From-project must have available transferable surplus; amount cannot exceed that surplus."
        ),
        roles=["super-admin", "finance"],
        enums=(
            "`month` / `year`: settlement month (required).\n"
            "`from_project_id`: contributor project with available surplus (required, must differ from `to_project_id`).\n"
            "`to_project_id`: receiver project (required).\n"
            "`amount`: positive decimal (required, gt:0, ≤ transferable surplus).\n"
            "Transferable = max(0, Previous + Monthly Total + Added − Deducted) before this settlement."
        ),
        body=body,
        json_body=True,
        responses=[
            example(
                "201 Created",
                201,
                ok("Cash station settlement created successfully.", SAMPLE_CASH_STATION_PAYLOAD),
                original_request=original,
            ),
            example(
                "422 Validation Error",
                422,
                {
                    "message": "The given data was invalid.",
                    "errors": {
                        "from_project_id": ["The from project id and to project id must be different."],
                        "amount": ["The amount field must be greater than 0."],
                    },
                },
                original_request=original,
            ),
            example(
                "422 No surplus available",
                422,
                {
                    "success": False,
                    "message": "Settlements can only transfer funds from a project with available surplus.",
                },
                original_request=original,
            ),
            example(
                "422 Exceeds transferable balance",
                422,
                {
                    "success": False,
                    "message": "The settlement amount exceeds the project's available transferable surplus.",
                },
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def cash_station_settlement_delete() -> dict:
    path = "cash-station/settlements/{{settlement_id}}"
    original = {"method": "DELETE", "header": header(), "url": url(path)}
    deleted_payload = {
        **SAMPLE_CASH_STATION_PAYLOAD,
        "projects": [
            {
                **SAMPLE_CASH_STATION_PROJECT,
                "added_contribution": "0.00",
                "deducted_contribution": "0.00",
                "net_cash_fund": "1000.00",
            }
        ],
        "settlements": [],
    }
    return req(
        "Delete Settlement",
        "DELETE",
        path,
        description="Delete a settlement by id. Response returns the updated Cash Station payload for that settlement's month.",
        roles=["super-admin", "finance"],
        enums="`settlement_id`: path param (cash_station_settlements.id).",
        responses=[
            example(
                "200 OK",
                200,
                ok("Cash station settlement deleted successfully.", deleted_payload),
                original_request=original,
            ),
            example(
                "404 Not Found",
                404,
                {"success": False, "message": "Cash station settlement not found."},
                original_request=original,
            ),
            *std_auth_errors(original, include_not_found=True),
        ],
    )


def cash_station_folder_items() -> list:
    return [
        cash_station_show(),
        cash_station_carry_forward(),
        cash_station_settlement_create(),
        cash_station_settlement_delete(),
    ]


# --- Administrative Debt Settlement ---

SAMPLE_ADS_PROJECT = {
    "project_id": 1,
    "project_name": "مشروع أ",
    "net_cash_balance": "500.00",
    "administrative_debt": "120.00",
    "recoverable_amount": "120.00",
    "remaining_debt": "120.00",
    "settlement_status": "unpaid",
    "can_settle": True,
}

SAMPLE_ADS_PAYLOAD = {
    "month": {"month": 7, "year": 2026},
    "projects": [SAMPLE_ADS_PROJECT],
}

ADS_ENUMS = (
    "`month`: integer 1–12 (required)\n"
    "`year`: integer 2000–2100 (required)\n\n"
    "Lists **only** projects with remaining Administrative Debt > 0 for the month.\n"
    "Table columns only: project_id/name, net_cash_balance, administrative_debt, "
    "recoverable_amount, remaining_debt, settlement_status, can_settle.\n"
    "Surplus / Recoverable Amount uses Cash Station `net_cash_fund` (max(0, …)), not Daily Journal `fund_balance`.\n"
    "Administrative Debt / Remaining Debt = month-end journal `accumulated_administrative_debt` "
    "(ADS settle mutates that tip; settlement rows are not subtracted again).\n"
    "Recoverable Amount = min(debt, available surplus after cash-box reservation). "
    "Available surplus = max(0, net_cash_fund) − Σ prior ADS settlements this month/project "
    "(Net Cash itself is not mutated).\n"
    "Allocation priority on execute: Cash Box capacity → Current Admin Debt → Carried Admin Debt. "
    "Only debt-allocated amounts credit the org Administrative Percentage Balance.\n"
    "Settlement updates admin % balance, remaining debt, and related indicators immediately; "
    "it does **not** deduct from Net Cash Fund.\n"
    "`settlement_status`: unpaid | partial | paid.\n"
    "`can_settle`: true when surplus capacity and recoverable amount are both > 0.\n"
    "Realtime: room `administrative-debt-settlements.{YYYY}-{MM}` → `administrative-debt-settlements.updated`."
)


def administrative_debt_settlements_list() -> dict:
    path = "administrative-debt-settlements"
    query = [
        {"key": "month", "value": "7", "description": "Required. Month 1-12"},
        {"key": "year", "value": "2026", "description": "Required. Year 2000-2100"},
    ]
    original = {"method": "GET", "header": header(), "url": url(path, query)}
    return req(
        "List Administrative Debt Settlements",
        "GET",
        path,
        description=(
            "Month table of projects with outstanding administrative debt and surplus-based "
            "recoverable amounts. Reuses Cash Station nets; does not recompute monthly math."
        ),
        roles=["super-admin", "finance"],
        enums=ADS_ENUMS,
        query=query,
        responses=[
            example(
                "200 OK",
                200,
                ok("Administrative debt settlements fetched successfully.", SAMPLE_ADS_PAYLOAD),
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def administrative_debt_settlement_create() -> dict:
    path = "administrative-debt-settlements"
    body = {"year": 2026, "month": 7, "project_id": 1, "amount": 50}
    original = {"method": "POST", "header": header(json_body=True), "body": body_raw(body), "url": url(path)}
    return req(
        "Execute Administrative Debt Settlement",
        "POST",
        path,
        description=(
            "Recover project administrative debt from month Net Cash surplus capacity. "
            "Credits org Administrative Percentage Balance for debt-allocated amounts. "
            "Reduces journal `accumulated_administrative_debt` so later days inherit the settled "
            "balance. Does not mutate Net Cash Fund, day `administrative_debt`, "
            "Cash Station inter-project settlements, or journal `fund_balance`. "
            "`amount` optional (defaults to full recoverable)."
        ),
        roles=["super-admin", "finance"],
        enums=(
            "`year` / `month`: settlement month (required).\n"
            "`project_id`: indebted project (required).\n"
            "`amount`: optional positive decimal ≤ debt and ≤ surplus; omit for full recoverable.\n"
            "Requires debt > 0 and available Net Cash surplus capacity > 0.\n"
            "Priority: Cash Box capacity → Current Admin Debt → Carried Admin Debt."
        ),
        body=body,
        json_body=True,
        responses=[
            example(
                "201 Created",
                201,
                ok("Administrative debt settlement created successfully.", SAMPLE_ADS_PAYLOAD),
                original_request=original,
            ),
            example(
                "422 Requires surplus",
                422,
                {
                    "success": False,
                    "message": "Administrative debt settlement requires a positive Net Cash surplus for the project.",
                },
                original_request=original,
            ),
            *std_auth_errors(original, include_validation=True),
        ],
    )


def administrative_debt_settlement_folder_items() -> list:
    return [
        administrative_debt_settlements_list(),
        administrative_debt_settlement_create(),
    ]


# --- Media ---

def media_upload() -> dict:
    path = "media"
    formdata = [
        {"key": "file", "type": "file", "src": [], "description": "Required file upload"},
        {"key": "model_type", "value": "Modules\\Project\\Models\\Project", "type": "text", "description": "FQCN of Eloquent model"},
        {"key": "model_id", "value": "1", "type": "text", "description": "Model primary key"},
        {"key": "collection", "value": "default", "type": "text", "description": "Optional media collection name"},
    ]
    original = {
        "method": "POST",
        "header": [{"key": "Accept", "value": "application/json"}],
        "body": {"mode": "formdata", "formdata": formdata},
        "url": url(path),
    }
    return req(
        "Upload Media",
        "POST",
        path,
        description="No auth middleware on route. Multipart form. `model_type` must be a valid Eloquent class.",
        roles=["public (no role middleware)"],
        body_mode="formdata",
        formdata=formdata,
        auth_required=False,
        no_auth_header=True,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Media uploaded successfully.",
                    {
                        "id": 1,
                        "fileName": "doc.pdf",
                        "mimeType": "application/pdf",
                        "size": "120 KB",
                        "urls": {"original": "http://localhost/storage/1/doc.pdf"},
                        "createdAt": "2026-07-28T12:00:00.000000Z",
                    },
                ),
                original_request=original,
            ),
            example("422 Validation Error", 422, VALIDATION, original_request=original),
            example("404 Model not found", 404, NOT_FOUND, original_request=original),
        ],
    )


def media_show() -> dict:
    path = "media/{{media_id}}"
    original = {"method": "GET", "header": header(auth=False), "url": url(path)}
    return req(
        "Show Media",
        "GET",
        path,
        description="Fetch media metadata.",
        roles=["public (no role middleware)"],
        auth_required=False,
        no_auth_header=True,
        responses=[
            example(
                "200 OK",
                200,
                ok(
                    "Media fetched successfully.",
                    {
                        "id": 1,
                        "fileName": "doc.pdf",
                        "mimeType": "application/pdf",
                        "size": "120 KB",
                        "urls": {"original": "http://localhost/storage/1/doc.pdf"},
                        "createdAt": "2026-07-28T12:00:00.000000Z",
                    },
                ),
                original_request=original,
            ),
            example("404 Not Found", 404, NOT_FOUND, original_request=original),
        ],
    )


def media_download() -> dict:
    path = "media/{{media_id}}/download"
    original = {"method": "GET", "header": [], "url": url(path)}
    return req(
        "Download Media",
        "GET",
        path,
        description="Binary file download (not JSON).",
        roles=["public (no role middleware)"],
        auth_required=False,
        no_auth_header=True,
        responses=[
            example("200 OK (file)", 200, "(binary file stream)", original_request=original),
            example("404 Not Found", 404, NOT_FOUND, original_request=original),
        ],
    )


def media_delete() -> dict:
    path = "media/{{media_id}}"
    original = {"method": "DELETE", "header": header(auth=False), "url": url(path)}
    return req(
        "Delete Media",
        "DELETE",
        path,
        description="Delete media by id.",
        roles=["public (no role middleware)"],
        auth_required=False,
        no_auth_header=True,
        responses=[
            example("200 OK", 200, ok("Media deleted successfully."), original_request=original),
            example("404 Not Found", 404, NOT_FOUND, original_request=original),
        ],
    )


# ---------------------------------------------------------------------------
# Collection assembly (role-based)
# ---------------------------------------------------------------------------

def build() -> dict:
    reference = folder(
        "00. Reference - Roles & Enums",
        [],
        description=ENUMS_DOC.strip(),
    )

    auth_folder = folder(
        "01. Auth - Login / Logout",
        [
            login_item("Login as Super Admin", "super_admin", role="super-admin"),
            login_item("Login as Finance", "finance_user", role="finance"),
            login_item("Login as Inventory", "inventory_user", role="inventory"),
            refresh_item(),
            logout_item(),
            realtime_auth_item(),
        ],
        description=(
            "Run a Login request first to populate `{{token}}`. Switch roles by re-logging in. "
            "Realtime Auth validates the token for the Socket.IO sidecar (`realtime/`)."
        ),
    )

    public_folder = folder(
        "02. Public (no auth / no role)",
        [
            folder("Settings", [settings_list()]),
            folder(
                "Media",
                [media_upload(), media_show(), media_download(), media_delete()],
            ),
        ],
        description="Endpoints without `auth:sanctum` / role middleware.",
    )

    super_admin = folder(
        "03. Role: super-admin",
        [
            folder(
                "Users (Auth module `/auth/users`)",
                [auth_users_list(), auth_users_create(), auth_users_update(), auth_users_delete()],
            ),
            folder(
                "Users & Roles (Authorization `/users`, `/roles`)",
                [
                    roles_list(),
                    authz_users_list(),
                    authz_users_update(),
                    authz_users_delete(),
                    authz_users_status(),
                ],
            ),
            folder("Settings (write)", [settings_update(), settings_bulk()]),
            folder(
                "Categories",
                [categories_list(), categories_create(), categories_update(), categories_delete()],
            ),
            folder(
                "Projects",
                [
                    projects_list(),
                    projects_show(),
                    projects_store(),
                    projects_update(),
                    projects_delete(),
                    projects_archive(),
                    projects_restore(),
                    financial_settings_show(),
                    financial_settings_update(),
                    calculate_deductions(),
                    calculate_project_deduction(),
                ],
            ),
            folder(
                "Daily Journal",
                [journal_show(), journal_save(), journal_update(), journal_repay_debt()],
            ),
            folder(
                "Administration Rates",
                [administration_rates_show()],
            ),
            folder("Cash Station", cash_station_folder_items()),
            folder("Administrative Debt Settlement", administrative_debt_settlement_folder_items()),
            folder("Inventory", inventory_folder_items()),
        ],
        description=(
            "**Full access.** Middleware: `role:super-admin` (or included in multi-role routes).\n\n"
            "Also inherits everything available to finance & inventory."
        ),
    )

    finance = folder(
        "04. Role: finance",
        [
            folder(
                "Projects (read + deductions + financial settings read)",
                [
                    projects_list(),
                    projects_show(),
                    categories_list(),
                    financial_settings_show(),
                    calculate_deductions(),
                    calculate_project_deduction(),
                ],
            ),
            folder(
                "Daily Journal",
                [journal_show(), journal_save(), journal_update(), journal_repay_debt()],
            ),
            folder(
                "Administration Rates",
                [administration_rates_show()],
            ),
            folder("Cash Station", cash_station_folder_items()),
            folder("Administrative Debt Settlement", administrative_debt_settlement_folder_items()),
        ],
        description=(
            "**Allowed:** list/show projects & categories, financial settings GET, "
            "calculate deductions, daily journal CRUD, administration rates, cash station "
            "(show / carry-forward / settlements), administrative debt settlement.\n\n"
            "**Denied (403):** create/update/delete projects & categories, update financial settings, "
            "inventory module, user/role/settings admin endpoints."
        ),
    )

    inventory = folder(
        "05. Role: inventory",
        [
            folder(
                "Projects & Categories (read-only)",
                [projects_list(), projects_show(), categories_list()],
            ),
            folder("Inventory", inventory_folder_items()),
        ],
        description=(
            "**Allowed:** inventory items & movements CRUD, list/show projects, list categories.\n\n"
            "**Denied (403):** project/category writes, financial settings, deductions, daily journal, user admin."
        ),
    )

    # Flat module index for discoverability (every endpoint once more, tagged by roles)
    by_module = folder(
        "06. All Endpoints by Module (index)",
        [
            folder("Auth", [login_item("Login", "super_admin", role="super-admin"), refresh_item(), logout_item(), realtime_auth_item(), auth_users_list(), auth_users_create(), auth_users_update(), auth_users_delete()]),
            folder("Authorization", [roles_list(), authz_users_list(), authz_users_update(), authz_users_delete(), authz_users_status()]),
            folder("Settings", [settings_list(), settings_update(), settings_bulk()]),
            folder("Categories", [categories_list(), categories_create(), categories_update(), categories_delete()]),
            folder(
                "Projects",
                [
                    projects_list(),
                    projects_show(),
                    projects_store(),
                    projects_update(),
                    projects_delete(),
                    projects_archive(),
                    projects_restore(),
                    financial_settings_show(),
                    financial_settings_update(),
                    calculate_deductions(),
                    calculate_project_deduction(),
                ],
            ),
            folder("Daily Journal", [journal_show(), journal_save(), journal_update(), journal_repay_debt()]),
            folder("Administration Rates", [administration_rates_show()]),
            folder("Cash Station", cash_station_folder_items()),
            folder("Administrative Debt Settlement", administrative_debt_settlement_folder_items()),
            folder("Inventory", inventory_folder_items()),
            folder("Media", [media_upload(), media_show(), media_download(), media_delete()]),
        ],
        description="Complete inventory of all API routes, grouped by module. Each request description lists allowed roles.",
    )

    return {
        "info": {
            "_postman_id": uid(),
            "name": "Rashid API",
            "description": (
                "Complete role-based API collection for Rashid Financial.\n\n"
                + ENUMS_DOC
                + "\n\n**How to use**\n"
                "1. Set `base_url` (default `http://localhost:8000/api/v1`).\n"
                "2. Run **Login as …** under Auth to store Bearer `token`.\n"
                "3. Open the folder matching the role you logged in as.\n"
                "4. Each request includes saved example responses for success and error statuses.\n"
                "5. For live updates: start `realtime/` (`npm start`), set Laravel "
                "`BROADCAST_CONNECTION=socketio`, then use `socket.io-client` with the Sanctum token. "
                "Call **Realtime Auth** to verify handshake. Notifications have **no REST CRUD**.\n"
            ),
            "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
        },
        "auth": {
            "type": "bearer",
            "bearer": [{"key": "token", "value": "{{token}}", "type": "string"}],
        },
        "variable": [
            {"key": "base_url", "value": "http://localhost:8000/api/v1"},
            {"key": "token", "value": ""},
            {"key": "refresh_token", "value": ""},
            {"key": "current_role", "value": ""},
            {"key": "locale", "value": "ar"},
            {"key": "user_uuid", "value": ""},
            {"key": "category_id", "value": "1"},
            {"key": "project_id", "value": "1"},
            {"key": "inventory_item_id", "value": "1"},
            {"key": "settlement_id", "value": "1"},
            {"key": "media_id", "value": "1"},
            {"key": "setting_key", "value": "admin_fee_percentage"},
            {"key": "socket_io_url", "value": "http://127.0.0.1:3001"},
        ],
        "item": [
            reference,
            auth_folder,
            public_folder,
            super_admin,
            finance,
            inventory,
            by_module,
        ],
    }


def main() -> None:
    collection = build()
    OUT.write_text(json.dumps(collection, ensure_ascii=False, indent=2), encoding="utf-8")
    # Count requests
    def count_items(items):
        n = 0
        for i in items:
            if "item" in i:
                n += count_items(i["item"])
            else:
                n += 1
        return n

    total = count_items(collection["item"])
    print(f"Wrote {OUT} with {total} requests")


if __name__ == "__main__":
    main()
