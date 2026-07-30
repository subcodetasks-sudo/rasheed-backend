# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

Rashid API is a Laravel 11 backend for "Rashid Financial", a project-based financial management system. It is a
modular monolith built on `nwidart/laravel-modules`: all domain code lives under `Modules/*`, and `app/` only holds
thin cross-cutting concerns (base controller, media handling, exception handler, generic query helpers).

Active modules (see `modules_statuses.json`): `User`, `Authorization`, `Settings`, `Project`, `DailyJournal`,
`AdministrationRates`, `Inventory`.

## Common commands

```bash
composer install                 # install PHP deps (also merges each Modules/*/composer.json via composer-merge-plugin)
php artisan serve                # run the app
php artisan migrate               # run root + module migrations
php artisan module:migrate <Name> # run a single module's migrations
npm run dev / npm run build       # Vite assets (Tailwind v4)

php artisan test                            # full suite
php artisan test --filter=ProjectApiTest    # single test class
php artisan test tests/Feature/Project/ProjectApiTest.php
vendor/bin/phpunit --filter test_method_name

vendor/bin/pint                  # code style (Laravel Pint, no custom pint.json — defaults apply)
```

Tests use an in-memory SQLite DB and array/sync drivers for cache/session/queue (see `phpunit.xml`), so no local DB
setup is required to run the suite.

**Note on test locations**: despite each module having its own `tests/Feature` and `tests/Unit` folders, those are
currently empty stubs. All real tests live in the root `tests/Feature/<Module>` and `tests/Unit/<Module>` directories
(e.g. `tests/Feature/Project/ProjectApiTest.php`, `tests/Unit/Project/CreateProjectWorkflowTest.php`). Follow that
convention for new tests rather than adding tests inside `Modules/*/tests`.

## Architecture

### Module layout

Each module under `Modules/<Name>/app` mirrors a standard Laravel `app/` structure (`Http/Controllers/V1`,
`Http/Requests`, `Http/Resources`, `Models`, `Policies`, `Providers`, etc.), plus its own `database/migrations`,
`routes/api.php`, and `config/config.php`. Modules are registered/bootstrapped via `Modules/<Name>/Providers/*` and
`Modules/<Name>/app/Providers/*ServiceProvider.php` (route/event/config/view registration, policy bindings, and
container bindings for module-specific services).

Routes are versioned: `Modules/<Name>/routes/api.php` groups everything under `prefix('v1')` and (for authenticated
modules) `middleware(['auth:sanctum', 'role:...'])`. Controllers are invokable single-action classes (`__invoke`),
one per endpoint, named after the HTTP action (`StoreProjectController`, `ArchiveProjectController`, etc.) — **except**
`User` and `Authorization`, which mix in traditional multi-method resource controllers (see "User vs Authorization"
below).

**Namespace inconsistency**: `Project`, `DailyJournal`, `Inventory`, `AdministrationRates`, and `Settings` use the
standard nwidart namespace (`Modules\Project\Http\Controllers\V1\...`, no `app` segment even though the file lives
in `app/`). `User` and `Authorization` instead namespace classes with a literal `app` segment
(`Modules\User\app\Http\Controllers\V1\...`, `Modules\Authorization\app\Http\Controllers\V1\...`) — this matches
their `composer.json` `psr-4` mapping (`"Modules\\User\\": "app/"` is what actually makes this legal; the modules
just chose to additionally repeat `app` in the namespace). Match whichever convention the module you're editing
already uses; don't "fix" one module's namespace to match another's.

### Request flow: Controller → Workflow → Action

The `Project` module is the clearest example of the intended pattern for business logic:

1. **Controller** — invokable, does authorization (`$this->authorize(...)`) and translates the validated
   `FormRequest` into a typed input, then delegates to a Workflow. Returns responses via the shared helper methods
   on the base controller (`successResponse`, `errorResponse`, `notFound`, `paginated`, ...).
2. **DTO** (`app/DTOs/*Data.php`) — readonly value object built from validated request data via `fromArray()`,
   converting raw strings into backed enums (`FundType`, `ProjectStatus`, `OperationalDeductionType`).
3. **Workflow** (`app/Workflows/<Domain>/*Workflow.php`) — orchestrates a use case inside a `DB::transaction`,
   calling one or more Actions in sequence and dispatching domain events (`ProjectCreated`, `ProjectUpdated`, ...)
   at the end. This is where cross-cutting business rules and multi-step coordination live.
4. **Action** (`app/Actions/<Domain>/*Action.php`) — a single-purpose class with one `execute()` method that
   performs one persistence/calculation step (e.g. `CreateProjectAction`, `CalculateOperationalSettingsAction`).

`DailyJournal` and `Inventory` follow the same Controller → Workflow → Action chain (e.g.
`SaveDailyJournalController → SaveDailyJournalWorkflow → CalculateAdministrativeFeesAction/...`,
`CreateOutgoingStockController → CreateOutgoingStockWorkflow → InventoryFifoService`). When adding a new endpoint,
follow this same chain rather than putting logic directly in the controller.

### Listing/filtering endpoints

List endpoints use a dedicated `app/Queries/<Domain>Query.php` class (e.g. `ListProjectsQuery`,
`ListInventoryItemsQuery`) that applies domain-specific query scoping (tabs, date ranges, etc.) and then delegates
generic filter/sort/search behavior to `App\Support\Query\BaseQueryService`, configured with `allowedFilters()`,
`allowedSorts()`, `allowedSearch()`. This `BaseQueryService` reads `filter[...]`, `sort`, and `search` query-string
params and is shared across modules — reuse it for any new list endpoint instead of hand-rolling filtering.

### Pluggable business rules (constraint registry pattern)

`Modules\Project\Contracts\ProjectDeletionConstraint` is an interface for objects that can block deletion of a
`Project` (`blocks(Project $project): ?string`). Implementations are collected into a singleton
`ProjectDeletionConstraintRegistry` via container tagging (`$app->tagged('project.deletion_constraints')`),
registered in `ProjectServiceProvider::register()`. Other modules register their own constraints by tagging in their
own service provider — e.g. `DailyJournalServiceProvider` tags `HasJournalEntriesConstraint`, which blocks deleting
a project that has any `daily_journal_entries` rows. Use this pattern (interface + tagged registry) when a module
needs an open-ended, extensible set of validation/business rules rather than a hardcoded if-chain. See also
"Delete-project business rules" below.

### Error handling

API errors are normalized to a consistent JSON envelope (`{success, message, data|errors}`) by the `withExceptions()`
renderable registered in `bootstrap/app.php`. Note: `app/Exceptions/Handler.php::render()` also contains normalization
logic but is **dead code** — Laravel 11's `bootstrap/app.php`-based exception handling does not autoload or invoke
`app/Exceptions/Handler.php`, so only what's registered in `bootstrap/app.php` actually runs, and it currently only
handles `BusinessException`. `Modules\Project\Exceptions\BusinessException` is the pattern for domain-specific
exceptions (used across modules, e.g. `Modules\Inventory\Services\InventoryFifoService` throws it for insufficient
stock): it carries an HTTP-ish status code (default 422) and is caught centrally rather than per-controller. Other
modules should throw this same `BusinessException` (or register their own handling for a new exception type) in
`bootstrap/app.php` (not `app/Exceptions/Handler.php`) to get mapped correctly.

### Authorization

- `spatie/laravel-permission` provides roles/permissions (guard `web`), enforced via Spatie's own `role`/`permission`
  middleware. Roles and their permission sets are seeded in
  `Modules/Authorization/database/seeders/RolesAndPermissionsSeeder.php`. **Current seeder state is `super-admin`,
  `inventory`, `finance`** — the intended product roles are exactly three: `super-admin`, **inventory manager**, and
  **fund manager**; the seeder has not been reconciled to that naming yet. Route middleware across modules already
  uses these role names directly (e.g. `role:super-admin|finance` on `DailyJournal`/`AdministrationRates` routes,
  `role:super-admin|inventory` on `Inventory` routes). Permissions mostly follow a `<verb>-<resource>` naming
  convention, e.g. `create-projects`, `view-finances` (not perfectly consistent — e.g. `create-user` is singular).
- Model-level authorization uses Laravel Policies (`ProjectPolicy`, `UserPolicy`, `RolePolicy`, `ActivityPolicy`),
  registered via `Gate::policy()` in each module's service provider and invoked explicitly in controllers with
  `$this->authorize(...)`.
- Middleware aliases (registered in `bootstrap/app.php`): `role`/`permission` (Spatie), `role_access`
  (`RoleAccessMiddleware`), `CheckStatus` (`CheckUserStatus`, blocks inactive users), `rate_limit`, `SetLocale`.

### Auth

Auth is Sanctum-based but not the default SPA/stateless flow: `Modules\User\Services\AuthService::login()` (note:
lives at `Modules/User/Services/AuthService.php`, not under `app/` like the rest of the module) uses `Auth::once()`
for one-off credential verification, checks `$user->isActive()`, then issues a Sanctum personal access token with a
2-hour expiry (`createToken('auth_token', ['*'], now()->addHours(2))`). There is also a separate `RefreshToken`
model/table (see `Modules/User/app/Http/Requests/RefreshTokenRequest.php`) but it is not currently wired into
`AuthService::login()`. **Refresh-token handling is owned by the frontend**, not this backend — don't build
backend refresh/rotation logic here unless explicitly asked.

### User vs Authorization: duplicated user CRUD

User management currently exists **twice**, under two different route prefixes, and both are actively tested
(`tests/Feature/User/UserListAndDeleteApiTest.php` exercises both):

- `Modules\User` — `v1/auth/users` (`Modules/User/routes/api.php`), invokable Action-backed controllers
  (`ListUsersController`, `CreateUserController`, `EditUserController`, `DeleteUserController`), each delegating to
  a plain `app/Actions/*Action.php` (no Workflow layer here). Grouped under `v1/auth`, gated by `role:super-admin`.
- `Modules\Authorization` — `v1/users` (`Modules/Authorization/routes/api.php`), a single traditional
  `UserController` (`index`/`update`/`destroy`/`updateStatus` methods) delegating to `Authorization\Services\UserService`,
  plus a separate `ListRolesController` for `v1/roles`. Also gated by `role:super-admin`.

There is no single owner: both read/write the same `Modules\User\Models\User` model. If you change list
filters/sorts, resource shape, or delete-guard behavior for users, check whether the change needs to be mirrored in
**both** controllers/actions, not just one — this is a known duplication, not an oversight to "clean up" unilaterally.

### Media & activity log

- File uploads go through `spatie/laravel-medialibrary`, wrapped by `app/Core` (`MediaServiceInterface`/
  `MediaService`, `HasStandardMedia` trait for models, `MediaResource`). A generic `App\Http\Controllers\Api\MediaController`
  exposes upload/show/download/destroy under `v1/media`, decoupled from any specific module.
- `spatie/laravel-activitylog` is installed for auditing (`ActivityPolicy` gates who can view logs).

### Localization

Default locale is Arabic (`APP_LOCALE=ar`, `APP_FALLBACK_LOCALE=ar`); `lang/ar` and `lang/en` hold UI-message
translations. The `SetLocale` middleware switches locale per-request. User-facing API messages should go through
`__('messages....')` (or the module's own lang namespace) rather than hardcoded strings, matching existing
controllers (e.g. `__('messages.project_created_successfully')`). Separately, **project domain content itself
(project/category names) is Arabic-only** — don't design bilingual name fields for `Project`/`Category`.

### Database

Local/dev/prod run on **MySQL** (`.env`: `DB_CONNECTION=mysql`). The automated test suite uses SQLite in-memory
instead (see `phpunit.xml`) purely for test isolation/speed — don't assume SQLite applies outside the test env.

### Enums as domain vocabulary

The `Project` module models its domain with PHP backed enums (`FundType`, `ProjectStatus`,
`OperationalDeductionType`) rather than string constants — DTOs, models, and validation all consume these enums.
`Inventory` follows the same convention (`InventoryMovementType`, `InventoryExpenseType`, `InventoryBatchSourceType`).
Follow this for new fixed-vocabulary fields instead of raw strings. There is deliberately **no `ProjectType` enum**
— a project's "type"/classification is its `Category` (`category_id`, selected by the frontend from a managed
list), not a fixed enum. `FundType` (`fund_type`: `fixed`/`variable`) is a separate, unrelated concept — don't
conflate the two or rename `fund_type` to `project_type`.

### Delete-project / delete-category business rules

`DeleteProjectController → DeleteProjectWorkflow → ValidateProjectDeletionAction → DeleteProjectAction`, with
`ValidateProjectDeletionAction` delegating to `ProjectDeletionConstraintRegistry` (see "constraint registry pattern"
above). Currently only `DailyJournal`'s `HasJournalEntriesConstraint` is registered (blocks deleting a project that
has journal entries) — `Inventory` does not yet register a constraint even though `inventory_movements` references
projects as `beneficiary_project_id`. Add constraints incrementally as each domain's deletion rule is actually
specified; don't assume all historical-data domains are covered yet.

Deleting a `Category` cascades to its projects rather than being blocked by them:
`DeleteCategoryWorkflow` runs `ValidateProjectDeletionAction` against every project in the category first (so any
project-level deletion constraint still applies), then deletes all of those projects, then deletes the category
itself — all inside one transaction. The `projects.category_id` foreign key is `restrictOnDelete()` (changed from
`cascadeOnDelete()` — see migration `2026_07_30_103500_change_projects_category_id_to_restrict_on_delete.php`), so a
raw DB-level category delete with projects still attached would fail; the workflow's explicit project deletion loop
is what makes category deletion actually work.

### DailyJournal: calculation engine

`Modules/DailyJournal/EQUATIONS.md` is the authoritative spec for this module's math — exact formulas, field
editability, the two-pass write flow (contributions require a second recalculation pass), the strict
recalculation pipeline order, and the debt-repayment priority rules. **Read that file before touching anything in
`DailyJournalCalculationService` or its component Actions** (`CalculateAdministrativeFeesAction`,
`CalculateOperationalDeductionsAction`, `ResolveAdministrativeExpenseAction`, `CalculateFundBalancesAction`,
`CalculateAdministrativeDebtAction`, `UpdateAccumulatedAdministrativeDebtAction`,
`RepayAdministrativeDebtAction`) — the ordering and rounding rules are load-bearing and easy to silently break.

Key cross-module dependencies baked into the equations:
- Administrative fee reads `Project.administrative_exempt` / `administrative_fee_percentage`.
- Operational deduction reads the *effective-for-that-date* pool from `Project`'s `OperationalDeductionRate` history
  (see next section) and each project's `OperationalDeductionType` (`relative`/`fixed`/`exempt`).
- Administrative expense sums `Inventory`'s persisted FIFO `total_cost` for outgoing, `administrative`-typed
  movements — it never recomputes FIFO itself.

### Operational deduction rate scheduling (effective-dated settings)

`Modules\Project\Models\OperationalDeductionRate` (`operational_deduction_rates` table: `amount`, `effective_from`)
stores a history of the shared operational-deduction pool amount, keyed by the date it takes effect.
`ResolveEffectiveOperationalDeductionAction::execute($date)` picks the latest row with `effective_from <= $date`
(default `1081.00` if none exist). `ScheduleOperationalDeductionChangeAction::execute($newAmount)` is how a settings
change gets written: it never mutates "today's" effective amount — it (1) backfills an open-ended history row if
none covers today yet, (2) pins today's date to whatever amount was already effective (so today can never
accidentally inherit the new value), and (3) writes the new amount effective *tomorrow*. This
next-calendar-day-effective pattern is intentional (per `EQUATIONS.md`) — don't "simplify" it to a single
upsert-by-today's-date, that would let a same-day settings change retroactively affect today's/historical journal
calculations.

### Inventory: FIFO costing

`Modules\Inventory\Services\InventoryFifoService` implements strict FIFO: incoming stock creates an
`InventoryBatch` (`unit_cost`, `original_quantity`, `remaining_quantity`); outgoing stock consumes batches
oldest-`received_at`-first under `lockForUpdate()`, recording one `InventoryBatchConsumption` row per batch touched
and summing `line_cost` into the movement's `total_cost`. That persisted `total_cost` is what `DailyJournal` later
reads for `administrative_expense` — never recompute FIFO cost outside this service. `InventoryBalanceService`
maintains each `InventoryItem`'s running balance/quantities and enforces minimum-stock checks after every movement.

### AdministrationRates

Read-only reporting module (`ShowAdministrationRatesController` → `BuildAdministrationRatesAction`): aggregates
`daily_journal_entries` (excluding `administrative_exempt` projects) into an all-time summary, a full calendar of
daily records for a given month (zero-filled for days with no entries), and monthly totals. It reuses
`Modules\Project\Actions\Project\ResolveAdminFeePercentageAction` for the current admin-fee percentage — there is no
persistence of its own beyond that read.

### Pending: not-yet-modeled deletion domains

`ValidateProjectDeletionAction`/the constraint registry can only block on domains that exist. Balance/ledger,
contributions, and any future domains beyond `DailyJournal` and `Inventory` don't have deletion constraints yet —
don't build out speculative constraint checks for domains that haven't been specified.
