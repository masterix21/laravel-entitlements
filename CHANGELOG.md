# Changelog

All notable changes to `laravel-entitlements` will be documented in this file.

## 1.3.0 - 2026-07-20

Feature release: two new read-only entitlement strategies — computed usage and boolean flags — with first-class Filament support, plus hardening fixes from the post-merge review.

### Added

- **`ComputedStrategy`** — usage comes from application code instead of usage rows: register a resolver with `Entitlements::resolveUsageUsing($type, fn ($subscriber) => ...)`; `available()` returns `max(0, capacity - resolver result)`. The resolver must return a non-negative integer (`ComputedUsageResolverException` otherwise) and is skipped entirely when the subscriber has no valid capacity for the type.
- **`BooleanStrategy`** — on/off entitlements checked with the new `Entitlements::allows($subscriber, $type)`; calling `can()` on a boolean type (or `allows()` on a quantified one) throws `UnsupportedEntitlementOperationException`.
- Both strategies extend the new abstract `ReadOnlyStrategy`: `consume()` and every release method throw, `reconcile()`/`recalculate()` leave their licenses untouched.
- **Filament**: boolean plan items render as an *Enabled* toggle instead of a quantity input in the plan form; quantity and flexibility fields are hidden and normalized to the 0/1 invariant on save.

### Fixed

- Creating a boolean plan item from the Filament UI failed with a `NOT NULL` violation: the hidden `quantity` and `is_flexible` fields were not dehydrated. Both now use `dehydratedWhenHidden()`.
- `Entitlements::snapshot()` no longer throws `ComputedUsageResolverException` for subscribers with no capacity for a computed type when no resolver is registered — the resolver is short-circuited at zero capacity.
- The boolean 0/1 invariant is enforced at the domain layer (`assignPlan()`, `changePlan()`, transitions): quantity overrides are ignored for boolean items and legacy quantities above 1 are clamped on assignment. The Assign Plan / Change plan modals no longer offer quantity inputs for boolean items.

### Translations

- New strings for the computed/boolean exceptions and the Filament toggle added to all shipped locales (`en`, `it`, `zh`, `ru`). Re-publish the translation files or add the new keys to your copies.

### Documentation

- README: quickstart sections for computed usage and boolean entitlements, plus `ComputedStrategy` / `BooleanStrategy` entries in the Strategies reference.

### Tests

- Suite grown from 104 to 126 tests (420 assertions), including the package's first real Livewire form test (mounting the Filament plan form end-to-end) replacing source-string assertions.

## 1.2.0 - 2026-07-18

Security hardening release: closes all 12 findings of an internal security review (ENT-01 … ENT-12) focused on entitlement integrity under concurrency. Most fixes are transactional locking with no API surface changes; a few adjust runtime behavior — read **Changed** before upgrading.

### Security

- **Slot strategy ignored `$amount` (ENT-01)** — `SlotStrategy::consume()` silently consumed a single slot regardless of the requested amount, letting the host application grant N units while accounting for one. It now consumes N slots as N single-slot usages, spread across licenses (soonest-expiring first) atomically under row locks, and returns the first usage created.
- **Plan transitions could be applied twice (ENT-02, ENT-04)** — `applyTransition()` re-reads the transition under `lockForUpdate` and aborts unless it is still `Pending`, so overlapping scheduler runs (`entitlements:apply-transitions` without `withoutOverlapping`, multiple workers) can no longer duplicate active license groups or overwrite an `Applied` outcome with `Failed`. `cancelTransition()` performs its check-and-update atomically under the same lock, closing the cancel/apply race.
- **Category exclusivity TOCTOU (ENT-03)** — the exclusivity check and the license inserts are now serialized under a `lockForUpdate` on the category row (with the `allows_multiple_active_plans` flag re-read under lock), inside the `assignPlan()` and apply transactions. Two concurrent assignments can no longer both land in an exclusive category.
- **Usages could be stranded on closed licenses (ENT-05)** — applying a transition now locks the old license group before any read and collects the usages to migrate with a locking read, so an in-flight `consume()` is either migrated to the new licenses or refused — never silently lost.
- **Stacked pending transitions both applied (ENT-12)** — an anchor can now hold at most one pending transition; see **Changed**.
- **Filament force release (ENT-11)** — the `forceRelease` action re-checks `status = Releasing` server-side; an `Active` usage cannot be force-released even bypassing the form's option validation.
- **Information exposure (ENT-09, ENT-10)** — `failure_reason` keeps the exception message only for the package's own domain exceptions (already translated and user-safe); any other throwable is logged in full and replaced with a generic translated message. `NoEntitlementAvailableException` no longer embeds the subscriber class and ID in its message.

### Changed

- `SlotStrategy::consume()` honors `$amount` (previously always consumed 1) and throws `InvalidArgumentException` for non-positive amounts, matching `PoolStrategy`.
- `Entitlements::changePlan()` **replaces** a previous pending transition for the same anchor: the old one is cancelled atomically (dispatching `PlanTransitionCancelled`) before the new one is created.
- Quantity overrides `<= 0` now throw `InvalidArgumentException` from `assignPlan()`, `changePlan()` and the apply-time revalidation (previously only the Filament form validated them).
- `NoEntitlementAvailableException` has a translated, user-safe message. Code inspecting the old message should switch to the new readonly properties `subscriber`, `type` and `requested`. Its constructor is now private — build it via `forSubscriber()`.
- `PlanTransition.failure_reason` contains a generic message for unexpected (non-domain) errors; the full exception is in the application log.

### Documentation

- The README **Authorization** section now covers both Filament surfaces (plugin resources and `LicensesRelationManager`), includes a `Gate::policy` registration example for the package models, and calls out that the custom actions (Assign Plan, Change plan, Force Release Slot) are **not** covered by model policies and must be gated explicitly.
- New **Deleting licenses is destructive** subsection: the delete action cascades to every `LicenseUsage`; to keep an append-only audit trail, deny `delete` in your `License` policy and close groups via `ends_at` instead.
- The pending-transition replace semantics are documented in the plan transitions section.

### Translations

- Two new strings (generic transition failure, no-entitlement message) added to all shipped locales (`en`, `it`, `zh`, `ru`). If you published the translation files, re-publish them or add the new keys to your copies.

### Tests

- Suite grown from 92 to 104 tests (302 assertions): regression coverage for every finding, including state guards for the transition races (re-applying an `Applied`/`Cancelled` transition throws and alters nothing) and atomicity of rejected assignments.

## 1.1.5 - 2026-07-08

### Fixed

- `PlanItemFactory` no longer references the workbench `TestType` (usable by consumers). It now resolves the entitlement type from the consumer's configured `entitlements.type_enum`, falling back to the workbench enum only inside this package's own test environment (guarded by `class_exists`).
- `PlanResource` now exposes each item's `id` (needed to key quantity overrides). `Entitlements::assignPlan()` / `changePlan()` key `quantityOverrides` by `PlanItem` id, so a frontend can now map override inputs to items.

## 1.1.4 - 2026-07-08

Headless support for Laravel Inertia (and any JSON frontend). The write business logic already lives in the framework-agnostic `Entitlements` service, so this release adds only the read-side building blocks plus one DRY refactor. No routes, controllers, Form Requests or frontend components are shipped, and there is no dependency on `inertiajs/inertia-laravel`.

### Added

- `Entitlements::snapshot(Model $subscriber): EntitlementSnapshot` — returns `capacity`, `used` and `available` counts per configured entitlement type (one entry per `type_enum` case, expired licenses excluded, `used = capacity - available`). Returns immutable `EntitlementSnapshot` / `EntitlementTypeUsage` DTOs.
- Read-side JSON resources `PlanResource`, `LicenseResource` and `EntitlementSnapshotResource` (`LucaLongo\LaravelEntitlements\Http\Resources`) that serialize plans, licenses and the snapshot into stable, frontend-friendly payloads without leaking internal columns. Plain `JsonResource` classes usable from Inertia, a JSON API or Blade.
- `EntitlementTypeLabel::resolve()` helper that resolves an entitlement type to a human label, shared between the Filament UI and the new resources.
- Optional `?CarbonInterface $endsAt` parameter on `Entitlements::assignPlan()` to set an explicit license end date. Backward compatible: it is the last parameter and defaults to the plan-derived value when omitted.

### Changed

- `Filament\RelationManagers\LicensesRelationManager` now passes the explicit end date into `assignPlan()` instead of re-applying it after the call, removing duplicated logic and replacing a create-then-update with a single write.

### Documentation

- New "Using with Inertia (or any JSON frontend)" README section: reading via `snapshot()` + resources, writing via the facade from your own controllers, an unstyled Vue example, and a note on Inertia's `data` wrapping (using `->resolve()` to expose the prop unwrapped).

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
