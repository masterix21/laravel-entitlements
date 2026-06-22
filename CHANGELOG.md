# Changelog

All notable changes to `laravel-entitlements` will be documented in this file.

## 1.1.3 - 2026-06-22

### Fixed

- Correct the `vendor:publish` tag names in the documentation. The tags follow the package short name used by `spatie/laravel-package-tools` — `Package::shortName()` strips the `laravel-` prefix — so the working tags are `entitlements-config`, `entitlements-migrations` and `entitlements-translations`, not `laravel-entitlements-*` as previously documented (#4).
- Normalize the translations publish tag to `entitlements-translations` for consistency with the config and migration tags.

### Tests

- Add regression coverage asserting the three publish groups (`entitlements-config`, `entitlements-migrations`, `entitlements-translations`) are registered and that the legacy `laravel-entitlements-*` tags are not.

## 1.1.2 - 2026-06-11

### Fixed

- Prevent double `slot_used` decrement when the same usage is released concurrently: `forceRelease()` in both `PoolStrategy` and `SlotStrategy` now re-fetches the usage with a row lock inside the transaction and re-checks its status before touching the counter. `LicenseReleased` is only dispatched when the release actually happened.
- Log a warning when the guarded `slot_used` decrement affects no rows, so counter drift is visible instead of silent.
- Run `Entitlements::reconcile()` inside a transaction with a row lock on the license, preventing it from overwriting a concurrent `consume()`.

### Documentation

- New "Authorization" section in the README clarifying that the Filament actions delegate authorization entirely to the host application.

## 1.1.1 - 2026-06-05

### Fixed

- Eager load the `license` relation on open `LicenseUsage` rows (and the anchor `subscriber`) while applying a plan transition, preventing `LazyLoadingViolationException` in applications that enable `Model::preventLazyLoading()`. Transitions previously failed with the lazy loading message recorded in `failure_reason`.

## 1.1.0 - 2026-05-21

Plan transitions (upgrade/downgrade) become a first-class concept, the Filament UI gains a cluster with top-tab navigation and a complete "Change plan" workflow, and category exclusivity can now be enforced on every assignment.

### Plan transitions

- New public API: `Entitlements::changePlan(License $anchor, Plan $newPlan, PlanTransitionMode $mode, array $quantityOverrides = [], ?CarbonInterface $scheduledAt = null): PlanTransition`.
- `PlanTransitionMode` enum: `Immediate`, `EndOfPeriod`, `AtDate` (date-specific scheduling).
- New `entitlement_plan_transitions` table storing `apply_mode`, `status`, `scheduled_at`, `applied_at`, `failure_reason`, `quantity_overrides`, `new_anchor_license_id`.
- License groups are now immutable: every plan change creates a new group via `assignPlan`-style logic, migrates open `LicenseUsage` rows to the matching new license, closes the old group with `ends_at = transition_at`, and reconciles the new licenses.
- Pre-validation (re-run at apply time) blocks: non-anchor licenses, expired anchors, missing types in the target plan, insufficient capacity, exclusive-category violations, no-op transitions (same plan + same quantities).
- `AtDate` mode requires a future scheduled date.
- Perpetual plans (no `ends_at`) no longer reject `EndOfPeriod`: the transition is scheduled on the computed next billing cycle via the new `License::next_billing_at` accessor.
- `Entitlements::cancelTransition(PlanTransition)` cancels a pending transition.
- `Entitlements::applyDueTransitions(): int` materializes all `pending` rows with `scheduled_at <= now`; isolated failures don't stop siblings.
- New artisan command `entitlements:apply-transitions` — register it in your scheduler (e.g. `Schedule::command('entitlements:apply-transitions')->everyMinute();`) to apply scheduled transitions automatically.
- Events: `PlanTransitionScheduled`, `PlanTransitionApplied`, `PlanTransitionFailed`, `PlanTransitionCancelled`.
- Exceptions: `AnchorNotActiveForTransition`, `IncompatiblePlanTransition`, `InsufficientCapacityForTransition`, `InvalidTransitionScheduledDate`, `NoOpPlanTransition`, `PlanCategoryExclusivityViolation`, `TransitionAlreadyResolved`. All messages routed through `__()` and translated.

### Category exclusivity

- New `entitlement_plan_categories.allows_multiple_active_plans` column (boolean, default `true`).
- When `false`, a subscriber can hold at most one active anchor in that category. Enforced by both `assignPlan` and `changePlan`.

### Filament v5

- New `SubscriptionPlansCluster`: Plans and Plan Categories now live under a single "Subscription Plans" sidebar entry with top-tab sub-navigation.
- `LicensesRelationManager`:
  - Replaced the legacy "Edit plan" action with a unified **"Change plan"** modal: plan select (defaults to the current plan), apply-mode radio (`End of period` default / `Immediate` / `At a specific date`) with an inline date picker, and per-flexible-item quantity overrides pre-filled with the current group's slot totals.
  - **"Cancel pending change"** action and warning badge on anchors with a scheduled transition.
  - Anchors ordered by nearest expiration first; perpetuals next; expired at the bottom.
  - Expiration badge colored: green for perpetual, warning for active, danger for expired.
  - "Recurring" badge hidden on expired licenses.
  - Domain exceptions surface as translated "Operation not permitted" danger notifications instead of bubbling 500s.
- `PlanCategory` form gains the **"Allow multiple active plans"** toggle with explanatory helper text.

### Internationalization

- All new UI strings and exception messages translated in English, Italian, Chinese and Russian. `TranslationsTest` enforces locale key parity.

### Testing & quality

- Filament smoke tests (plugin registration, cluster wiring, resources, translations parity).
- Pest 4 suite: 70 tests / 175+ assertions covering the new transition flows and validations.
- Bumped requirement: `filament/filament` now requires `^5.0` (drops the optional v4 path).
- `tests/TestCase.php` only registers Filament service providers when the corresponding classes are installed, keeping `prefer-lowest` CI green.
- PHPStan model property docblocks updated; replaced the deprecated `VerifyCsrfToken` middleware with `PreventRequestForgery` in the workbench panel.

## 1.0.0 - 2026-05-20

First stable release. Full subscription plan and license management for Laravel applications, with project-specific entitlement types and an optional Filament v5 admin UI.

### Core engine

- Polymorphic `License` ownership via the `HasEntitlements` trait — any subscriber model (workspace, team, user, tenant) can hold licenses.
- Plans catalog: `PlanCategory`, `Plan`, `PlanItem` with translatable names, billing period (monthly/yearly), recurring or fixed-term plans, and flexible per-assignment quantities.
- Two consumption strategies out of the box:
  - `SlotStrategy` — one usage per subject, optional two-phase release (`Active → Releasing → Released`).
  - `PoolStrategy` — drainable counter across multiple licenses, FIFO by expiration.
- Project-specific entitlement type enum: declare your own `BackedEnum implements EntitlementType` and map each case to a strategy.
- `Entitlements` facade: `assignPlan`, `consume`, `requestRelease`, `confirmRelease`, `forceRelease`, `available`, `capacity`, `can`, `reconcile`, `recalculate`.
- Anchor / children grouping: `assignPlan()` threads `parent_id` so every license created in the same assignment is grouped under the first one.
- Domain events: `PlanAssigned`, `LicenseConsumed`, `ReleaseRequested`, `LicenseReleased`, `LicenseReconciled`.
- Exceptions: `NoEntitlementAvailableException`, `InvalidEntitlementTypeException` (validated at boot).
- Reconciliation: recompute `slot_used` from open usages, useful after manual intervention or drift.

### Filament v5 integration (optional)

- `EntitlementsPlugin` registers `PlanResource` and `PlanCategoryResource` on a panel. Both can be opted out individually.
- `PlanCategoryResource` nests under the "Subscription Plans" navigation item.
- `LicensesRelationManager` attachable to any subscriber resource, with:
  - **Assign Plan** action — Grid 2-columns, plan picker, start/end dates, pre-filled flexible quantities.
  - **Edit Plan** action — mirrors the Assign layout, plan shown read-only, edits dates and per-license quantities for the whole anchor group.
  - **Recalculate Usages** action — reconciles every license owned by the subscriber.
  - **Force Release Slot** action — admin override for usages stuck in `Releasing`.
- Enum case labels respect Filament's `HasLabel` contract; otherwise the case name is passed through `__()` for translation fallback.

### Translations

- JSON translation files for English (`en`), Italian (`it`), Chinese (`zh`) and Russian (`ru`), auto-loaded by the service provider.
- Publishable with `php artisan vendor:publish --tag="laravel-entitlements-translations"`.

### Compatibility & tooling

- PHP `^8.2` (lowered from `^8.4`).
- Laravel `^11 || ^12 || ^13`, Orchestra Testbench `^9 || ^10 || ^11`.
- Dev dependency constraints relaxed for PHP 8.2 (Pest 3/4, Collision 7/8, Larastan 2/3).
- GitHub Actions matrix runs PHP 8.2–8.5 across all supported Laravel versions on ubuntu-latest and windows-latest.
- 29 Pest tests, Larastan clean, Pint applied.
- Scopes use the `scopeXxx()` naming convention for Laravel 11 compatibility.
