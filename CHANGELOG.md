# Changelog

All notable changes to `laravel-entitlements` will be documented in this file.

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
