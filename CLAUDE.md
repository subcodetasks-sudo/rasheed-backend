# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

Rashid API is a Laravel 11 backend for "Rashid Financial", a project-based financial management system. It is a
modular monolith built on `nwidart/laravel-modules`: all domain code lives under `Modules/*`, and `app/` only holds
thin cross-cutting concerns (base controller, media handling, exception handler, generic query helpers).

Active modules (see `modules_statuses.json`): `User`, `Authorization`, `Settings`, `Project`, `DailyJournal`,
`AdministrationRates`, `Inventory`, `Dashboard`, `CashStation`, `CashFundExpenses`, `Notifications`,
`AdministrativeDebtSettlement`, `AdministrativeFund`, `OperationalFund`, `OperationalRate`, `ReportsCenter`,
`AdvancedReports`, `MonthlySummary`.

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

### CashStation: derived monthly cash-position ledger

`CashStation` has no controller-driven writes of its own for the numbers it displays — `BuildCashStationAction`
recomputes a whole month's view on demand by aggregating `daily_journal_entries` (revenue, expenses, admin
percentage collected net of debt, operational deduction) per active project, plus that project's
`accumulated_administrative_debt` as of month-end, minus whatever has already been recovered via
`AdministrativeDebtSettlement` for that project/month. The only things actually persisted in this module are
**settlements** (`CashStationSettlement`: a manual surplus transfer from one project to another within a month, via
`StoreCashStationSettlementController → StoreCashStationSettlementWorkflow`, validated by
`ValidateCashStationSettlementAction` against `BuildCashStationAction::transferableBalance()`) and **month
carries** (`CashStationMonthCarry`, via `CarryForwardCashStationController → CarryForwardCashStationWorkflow`,
which chain a month's ending position into the next month's `previous_monthly_total`). Because the view is
recomputed rather than stored, `Notifications`' `RefreshCashStationOnDailyJournalUpdate` listener re-broadcasts
`CashStationUpdated` (walking forward through any `CashStationMonthCarry` chain) whenever `DailyJournalUpdated`
fires, so open frontend sessions stay in sync without polling.

### AdministrativeDebtSettlement: recovering unpaid admin-fee debt

A project that can't pay its full `administrative_fee` on a given day accrues `accumulated_administrative_debt`
(see `EQUATIONS.md`). `AdministrativeDebtSettlement` (`StoreAdministrativeDebtSettlementController →
StoreAdministrativeDebtSettlementWorkflow`) lets that debt be recovered later out of a project's monthly surplus:
`ValidateAdministrativeDebtSettlementAction` computes how much of the current-month and carried-forward debt can be
allocated, `CreateAdministrativeDebtSettlementAction` persists the settlement with that snapshot, and — for the
allocated portion only — `Modules\DailyJournal\Services\AdministrativePercentageBalanceService::creditFromDebtSettlement()`
permanently credits the org-wide **Administrative Percentage Balance** pool (`admin_percentage_balance_credits`/
`admin_percentage_balance_debits`, see `EQUATIONS.md` "Administrative Percentage Balance"). This is deliberately
separate from the day-level `POST /daily-journals/repay-debt` flow: settlement-based recovery does **not** touch
Net Cash or `fund_balance`, it only affects the shared admin-percentage pool. The workflow re-dispatches
`CashStationUpdated` afterward since settling debt changes `remaining_administrative_debt` in the CashStation view.

### CashFundExpenses: read-only daily-expense matrix

Single-endpoint reporting module (`ShowCashFundExpensesController → ShowCashFundExpensesWorkflow →
BuildCashFundExpensesAction`, `GET /v1/cash-fund-expenses`). For a given month/year it aggregates
`daily_journal_entries.daily_expense + administrative_expense` per project per day straight off the DB (no other
module's derived data), returning a zero-filled day-by-day matrix per project plus each project's monthly total;
projects with a zero monthly total are dropped from the response. It has no persistence of its own — like
`AdministrationRates`, it's purely a read model. `RefreshCashFundExpensesOnDailyJournalUpdate` (registered on
`DailyJournalUpdated` in `Notifications\EventServiceProvider`) rebuilds and re-broadcasts `CashFundExpensesUpdated`
whenever a journal entry changes, the same push-refresh pattern `CashStation` uses.

### OperationalRate: read-only operational-deduction report

Mirrors `AdministrationRates`'s shape but for the operational-deduction side: `ShowOperationalRateController →
BuildOperationalRateAction` (`GET /v1/operational-rate`) aggregates `daily_journal_entries` for a month into a
zero-filled daily calendar plus relative/fixed/total operational-deduction summaries and monthly totals, split by
each project's `OperationalDeductionType`. Pure read model, refreshed via `Notifications` on `DailyJournalUpdated`
and `ProjectUpdated` (a project's deduction type/exemption can change which bucket a day's total counts toward).

### OperationalFund: manually-tracked daily operational expense vs. expected income

Unlike the read-only reporting modules, `OperationalFund` persists one number per day — `operational_fund_days.
operational_expense`, written via `UpdateOperationalFundDayController → UpdateOperationalFundDayWorkflow →
UpsertOperationalFundDayAction` (`PUT /v1/operational-fund/{date}`). "Expected operational income" for a day is
*not* stored — `ResolveExpectedOperationalIncomeAction` derives it live as `Project`'s effective-dated operational-
deduction pool (`ResolveEffectiveOperationalDeductionAction`, see "Operational deduction rate scheduling" below)
plus the sum of every active project's fixed operational deductions (`SumFixedOperationalDeductionsAction`, new
action in `Project`). `BuildOperationalFundDayAction` (`GET /v1/operational-fund/day?date=...`) returns a single
day's income/expense/net/status; `BuildOperationalFundMonthAction` (`GET /v1/operational-fund?month=&year=`) walks
the whole month carrying a running surplus accumulator forward day-to-day so each deficit day is marked
`covered`/`not_covered` against prior days' unspent surplus (`DeficitCoverageStatus`) — this accumulator is
recomputed on every read, never persisted.

### AdministrativeFund: monthly administrative-fund view with manual entries

Similar hybrid to `OperationalFund`: `administrative_fund_days` persists only manually-entered fields per day
(`individual_contributions`, `asset_administration`, free-text `notes`), via `UpdateAdministrativeFundDayController
→ UpsertAdministrativeFundDayAction` (`PUT /v1/administrative-fund/{date}`). Everything else in the view is derived
live by `BuildAdministrativeFundAction` (`GET /v1/administrative-fund?month=&year=`): `project_administration` sums
each day's `administrative_fee - administrative_debt + contribution` off `daily_journal_entries` (excluding
nothing — the same formula `CashStation`/`ReportsCenter` use), `debt_recovery` sums `AdministrativeDebtSettlement`'s
`allocated_current_debt + allocated_carried_debt` for that month keyed by the settlement's `created_at` date, and
`operational_administration` reuses `OperationalFund`'s persisted daily expense. Refreshed via `Notifications` on
`DailyJournalUpdated`, `OperationalFundUpdated`, and `AdministrativeDebtSettlementCreated`.

### ReportsCenter: flexible-period cross-project report

Single endpoint (`ShowReportsCenterController → BuildReportsCenterAction`, `GET /v1/reports-center`) accepting
either `period_type=month` (`month`+`year`) or `period_type=custom` (`start_date`+`end_date`). Reuses
`CashStation\BuildCashStationAction::monthlyAggregatesByProject()` (despite the name, it accepts an arbitrary date
range) and `DailyJournal\ReadAccumulatedAdministrativeDebtTipAction` to build a per-project income/expense/
administrative/operational/net/debt table, an income-vs-expense daily movement chart, an expense-distribution
breakdown, and a project-comparison chart — for active projects only. Pure read model; refreshed via
`Notifications`'s `RefreshReportsCenterOnFinancialUpdate` on essentially every financial-write event.

### AdvancedReports: month-comparison and inventory reports

Single endpoint (`ShowAdvancedReportsController → ShowAdvancedReportsWorkflow`, `GET /v1/advanced-reports?
report_type=&period=`) dispatching on `report_type` (`month_comparison` or `inventory`) with `period` restricted to
`3`/`6`/`12` months via `ResolveAdvancedReportPeriodAction` (last N calendar months ending at the current month).
`month_comparison` (`BuildMonthComparisonReportAction`) reuses `CashStation`'s per-month aggregates to build a
revenue/expense trend, an admin-vs-operational-deduction trend, and a month-over-month growth-rate table.
`inventory` (`BuildInventoryReportAction`) reports current `InventoryItem` balances/status against
`minimum_stock_level`, total FIFO-valued inventory (`ComputeInventoryFifoValueAction`, sums
`remaining_quantity * unit_cost` across all `InventoryBatch`es), and the most-consumed item for the period. Pure
read model; role-gated `super-admin|finance|inventory` (broader than the other new modules' `super-admin|finance`)
since it spans both the finance and inventory domains.

### Dashboard: home-screen summary

Single read-only endpoint (`ShowDashboardController`, `GET /v1/dashboard?date=YYYY-MM-DD`) built by
`BuildDashboardSummaryAction`: for a given day it aggregates that day's income/expenses/operational deduction
straight from `daily_journal_entries`, the current administrative-fee percentage (via
`AdministrationRates\BuildAdministrationRatesAction::administrativePercentageForDate()`), low-stock
`InventoryItem`s, a 7-day cash-movement history and 5-day recent-activity feed for the day's month, and (only when
that month has any journal activity) reuses `CashStation\BuildCashStationAction` for per-project surplus/deficit/
debt status counts. No persistence of its own. Refreshed via `Notifications`'s `RefreshDashboardOnFinancialUpdate`
on nearly every financial-write event across `Project`, `DailyJournal`, `Inventory`, `CashStation`,
`AdministrativeDebtSettlement`, `OperationalFund`, and `Settings`.

### MonthlySummary: cross-project contribution transfers

Aggregation + write module (`ShowMonthlySummaryController → ShowMonthlySummaryWorkflow → BuildMonthlySummaryAction`,
`GET /v1/monthly-summary`) that lists every active project's monthly net result (surplus/deficit, derived from
`CashStation\BuildCashStationAction`'s per-project monthly aggregates), outstanding `accumulated_administrative_debt`,
and any contribution settlements recorded for that month. A **contribution** is a `CashStationSettlement` row with a
non-null `contribution_type` (`Modules\MonthlySummary\Enums\ContributionType`: `fund_deficit` or
`administrative_debt`) — it reuses `CashStation`'s settlement table/model rather than introducing a new one.
`StoreMonthlySummaryContributionController → StoreMonthlySummaryContributionWorkflow`
(`POST /v1/monthly-summary/contributions`) transfers surplus from a contributor project to a beneficiary project
within the **same category**: `ValidateMonthlySummaryContributionAction` caps the amount at
`min(contributor's transferable surplus, beneficiary's remaining need)` — remaining need being either the
beneficiary's fund deficit or its outstanding administrative debt, depending on `contribution_type` — then
`CreateCashStationSettlementAction` persists it. Both contribution types then anchor their effect to the
beneficiary's month-tip `daily_journal_entries` row (the latest entry on/before the settlement's month-end):
`ApplyContributionAdministrativeDebtAction` reduces `accumulated_administrative_debt` on the anchor entry and every
later entry by the contributed amount (mirrored in reverse by `ReverseContributionAdministrativeDebtAction` when
`CancelMonthlySummaryContributionController` deletes a contribution); `ApplyContributionFundBalanceAction` instead
adds the amount to `fund_balance` on the anchor entry and every later entry (reversed by
`ReverseContributionFundBalanceAction`). If the beneficiary has no journal entry yet for that month at
apply-time, the settlement is left pending (`CashStationSettlement.journal_anchor_date` stays `null`) rather than
failing — `Notifications`' `ApplyPendingContributionAdministrativeDebtOnDailyJournalUpdate` /
`ApplyPendingContributionFundBalanceOnDailyJournalUpdate` retry the same action whenever `DailyJournalUpdated`
later fires for that beneficiary project, so a contribution recorded before any journal activity exists for the
month is never silently lost. Every write re-dispatches `CashStationUpdated`, `MonthlySummaryUpdated`, and
`AdministrativeDebtSettlementUpdated` since a contribution can change all three views.

### Notifications: activity feed + cross-module reactions + live delivery

`Notifications` is a passive subscriber module: its `EventServiceProvider` (registered explicitly from
`NotificationsServiceProvider::boot()`, not via Laravel's auto-discovery — `$shouldDiscoverEvents = false`) listens
for domain events from nearly every other module and reacts several ways:

1. **User-facing activity records** — `NotifyProjectActivity`, `NotifyCategoryActivity`, `NotifyUserActivity`,
   `NotifyDailyJournalActivity`, `NotifyInventoryActivity`, `NotifyCashStationActivity`,
   `NotifyAdministrativeDebtSettlementActivity`, `NotifySettingsActivity` each translate one module's events into a
   persisted `Notification` row via `NotificationService` (`notifyActivity`/`notifySuccess`/`notifyWarning`/
   `notifyDanger`/`notifyInfo`), which also dispatches `NotificationCreated` (Socket.IO) and announces the row for
   SSE delivery (see below).
2. **Cross-module read-model refresh** — one `Refresh<Module>On<Event>` listener per derived-view module
   (`CashStation`, `AdministrativeDebtSettlement`, `CashFundExpenses`, `MonthlySummary`, `AdministrativeFund`,
   `OperationalRate`, `ReportsCenter`, `AdvancedReports`, `Dashboard`) rebuilds and re-broadcasts that module's
   `*Updated` event whenever an upstream financial event fires. `EventServiceProvider::$listen` is the
   authoritative event→listener matrix — it grows every time a new derived-view module is added, so check it
   directly rather than trusting an enumeration here. Two listeners are not pure refreshes:
   `RefreshDailyJournalOnInventoryAdministrativeMovement` reacts to an outgoing `administrative`-typed inventory
   movement by calling `DailyJournal\RecalculateDailyJournalAction` (since `administrative_expense` sums
   Inventory's FIFO cost) and re-dispatching `DailyJournalUpdated` itself, cascading through the rest of the
   refresh listeners; `RefreshModulesOnSettingsUpdate` fans `Settings`' `SystemGeneralSettingsUpdated`/
   `MonthlyEmployeeSettingsUpdated` out to `Dashboard`, `OperationalRate`, `ReportsCenter`, `AdvancedReports`.
3. **Deferred contribution retry** — `ApplyPendingContributionAdministrativeDebtOnDailyJournalUpdate` /
   `ApplyPendingContributionFundBalanceOnDailyJournalUpdate` re-attempt `MonthlySummary` contributions left pending
   for lack of a journal anchor (see "MonthlySummary" above) whenever `DailyJournalUpdated` fires for one of the
   pending beneficiary projects.
4. **Administrative-debt alerts** — `SyncAdministrativeDebtAlertOnFinancialChange` calls
   `SyncAdministrativeDebtAlertAction` on every event that can change a project's `accumulated_administrative_debt`
   (journal updates, debt repayment, debt settlements, admin-debt contributions). It upserts a single per-project
   `Notification` (`type=warning`, identified by `meta.action === 'administrative_debt_alert'`) reflecting the
   current remaining debt, or deletes it once debt reaches zero — so there is always at most one live debt alert
   per project rather than one per triggering event.

Separately, `Modules\Notifications\Contracts\NotificationRule` + `NotificationRuleRegistry` is a third, unrelated
extension point: a rule declares which `context` string it `handles()` and is `evaluate()`-d against a subject
(e.g. `InventoryStockNotificationRule` for low-stock warnings). Unlike `Project`'s deletion-constraint registry
(container-tagged, open for any module to extend), this registry is a hardcoded list built in
`NotificationsServiceProvider::register()` — add new rules there, not via tagging.

### Notifications: Server-Sent Events (`GET /v1/notifications/stream`)

In-app notification delivery to the frontend goes over a plain SSE stream, not the Socket.IO channel described
below (that one is for other modules' domain events, e.g. `DailyJournalUpdated`/`CashStationUpdated`).
`StreamNotificationsController → NotificationSseService::stream()` returns a chunked `text/event-stream` response:
it replays notifications newer than the client's cursor (`Last-Event-ID` header or `?last_event_id=`; a fresh
connection with no cursor starts from the current max id — no history replay), then polls the `notifications`
table for new rows, interleaved with heartbeat comments, until a hard `max_duration_seconds` cutoff (the client is
expected to reconnect — standard SSE `retry:` behavior — since a PHP request worker can't hold a connection open
indefinitely). `NotificationService::createAndBroadcast()`/`SyncAdministrativeDebtAlertAction` both call
`NotificationSseService::announce()` inside `DB::afterCommit()` after every notification write, caching the latest
id purely as a wake hint for open streams to poll faster — the stream's actual data read is always the DB, never
the cache. Tunable via `config('notifications.sse.*')` (`poll_seconds`, `heartbeat_seconds`,
`max_duration_seconds`, `replay_limit`, `batch_limit`, `retry_milliseconds`, all overridable via
`NOTIFICATIONS_SSE_*` env vars). A request with `Accept: application/json` (not `text/event-stream`) gets a `406`
instead of hanging a worker on the stream — JSON/axios clients must not hit this endpoint directly.

### Realtime updates: Socket.IO broadcasting

Live updates ride Laravel's native broadcasting (`ShouldBroadcastNow` events — `DailyJournalUpdated`,
`CashStationUpdated`, `AdministrativeDebtSettlementUpdated`, `InventoryItemCreated`, `InventoryStockMoved`,
`NotificationCreated`) over a **custom `socketio` broadcast driver**, not Pusher/Reverb/Ably. The driver
(`App\Broadcasting\SocketIoBroadcaster`, registered via `Broadcast::extend('socketio', ...)` in
`AppServiceProvider::boot()`) doesn't hold a persistent connection to clients itself — on each `broadcast()` call it
opens a short-lived Socket.IO client connection (via `elephantio/elephant.io`, configured in `config/broadcasting.php`)
to an external Node.js sidecar server (source in `realtime/`, not part of the PHP app) and emits a
`server:broadcast` event carrying `{rooms, event, payload}`, authenticated with a shared `SOCKET_IO_SECRET`. The
sidecar is what actually holds client WebSocket connections and fans the event out to whichever rooms (channel
names, `private-`/`presence-` prefixes stripped) are subscribed. `App\Services\Broadcast\BroadcastService::emit()`
is a thin imperative wrapper around the same `Broadcast::connection()->broadcast()` call for one-off emits outside
of an event class; it also handles the `null`/`log` driver cases (used in tests/local dev, see `.env.example`).
`GET /v1/realtime/auth` (`App\Http\Controllers\Api\RealtimeAuthController`, `routes/api.php`) is the handshake
endpoint the sidecar calls to validate a client's Sanctum bearer token before admitting its socket connection —
`SocketIoBroadcaster::auth()` itself rejects any `private-`/`presence-` channel outright, so real per-user/private
channel authorization currently happens only at that handshake step, not per-channel.

### Pending: not-yet-modeled deletion domains

`ValidateProjectDeletionAction`/the constraint registry can only block on domains that exist. Balance/ledger,
contributions, and any future domains beyond `DailyJournal` and `Inventory` don't have deletion constraints yet —
don't build out speculative constraint checks for domains that haven't been specified.
