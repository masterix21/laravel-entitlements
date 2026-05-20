# Laravel Entitlements

[![Latest Version on Packagist](https://img.shields.io/packagist/v/masterix21/laravel-entitlements.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-entitlements)
[![Total Downloads](https://img.shields.io/packagist/dt/masterix21/laravel-entitlements.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-entitlements)
[![License](https://img.shields.io/packagist/l/masterix21/laravel-entitlements.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-entitlements)

A flexible entitlement management system for Laravel applications. Define subscription plans, issue licenses to any model, and track consumption — slot-based or pool-based — with project-specific entitlement types injected via configuration.

## Why

Every SaaS reinvents the same wheel: plans, plan items, licenses with start/end dates, usage tracking with two-phase release for some resources (a device that must confirm deactivation) and metered drain for others (a token pool). This package extracts that machinery so each project only declares the things that actually change: which entitlement types exist and how each one is consumed.

## Features

- **Polymorphic ownership** — any model with the `HasEntitlements` trait can hold licenses (workspace, team, user, tenant)
- **Plans catalog** — categorized plans with billing period (monthly/yearly), recurring or fixed-term, with translatable names
- **Plan items** — define how many slots of each type a plan grants; flexible items accept per-assignment overrides
- **Two consumption strategies out of the box**:
  - `SlotStrategy` — one usage per subject, with optional two-phase release (`Active → Releasing → Released`)
  - `PoolStrategy` — drainable counter across multiple licenses, FIFO by expiration
- **Project-specific type enum** — declare your own backed enum (e.g. `Device`, `AiTokens`, `Seat`, `ApiCall`) and map each case to a strategy
- **Domain events** — `PlanAssigned`, `LicenseConsumed`, `ReleaseRequested`, `LicenseReleased`, `LicenseReconciled`
- **Reconciliation** — recompute `slot_used` from actual open usages, useful after manual intervention or drift
- **Optional Filament v5 admin UI** — plug-in for Plans/Plan Categories management and a `LicensesRelationManager` for the subscriber resource

## Requirements

- PHP `^8.4`
- Laravel `^11 || ^12 || ^13`
- `spatie/laravel-package-tools`
- `spatie/laravel-translatable` (for translatable plan names)

## Installation

```bash
composer require masterix21/laravel-entitlements
```

Publish the config and migrations:

```bash
php artisan vendor:publish --tag="laravel-entitlements-config"
php artisan vendor:publish --tag="laravel-entitlements-migrations"
php artisan migrate
```

## Configuration

`config/entitlements.php` after publishing:

```php
return [
    // Required: the backed enum that implements EntitlementType
    'type_enum' => \App\Enums\LicenseType::class,

    // Override models if you want to extend them
    'models' => [
        'plan_category'  => \LucaLongo\LaravelEntitlements\Models\PlanCategory::class,
        'plan'           => \LucaLongo\LaravelEntitlements\Models\Plan::class,
        'plan_item'      => \LucaLongo\LaravelEntitlements\Models\PlanItem::class,
        'license'        => \LucaLongo\LaravelEntitlements\Models\License::class,
        'license_usage'  => \LucaLongo\LaravelEntitlements\Models\LicenseUsage::class,
    ],

    'table_names' => [
        'plan_categories' => 'entitlement_plan_categories',
        'plans'           => 'entitlement_plans',
        'plan_items'      => 'entitlement_plan_items',
        'licenses'        => 'entitlement_licenses',
        'license_usages'  => 'entitlement_license_usages',
    ],
];
```

The `type_enum` is validated at boot: if the class doesn't exist or doesn't implement `EntitlementType`, an `InvalidEntitlementTypeException` is thrown.

## Usage

### 1. Declare your entitlement types

Create a backed enum that implements `EntitlementType` and maps each case to a strategy:

```php
<?php

namespace App\Enums;

use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Strategies\PoolStrategy;
use LucaLongo\LaravelEntitlements\Strategies\SlotStrategy;

enum LicenseType: string implements EntitlementType
{
    case Device   = 'device';
    case AiTokens = 'ai_tokens';
    case Seat     = 'seat';

    public function strategy(): EntitlementStrategy
    {
        return match ($this) {
            self::Device   => new SlotStrategy(twoPhase: true),
            self::AiTokens => new PoolStrategy(),
            self::Seat     => new SlotStrategy(),
        };
    }
}
```

Reference it in `config/entitlements.php`:

```php
'type_enum' => \App\Enums\LicenseType::class,
```

### 2. Add the trait to the model that owns licenses

```php
use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelEntitlements\Concerns\HasEntitlements;

class Workspace extends Model
{
    use HasEntitlements;
}
```

The trait adds a `licenses()` morphMany relationship.

### 3. Create plans

```php
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanCategory;

$category = PlanCategory::create(['name' => ['en' => 'Business']]);

$plan = Plan::create([
    'plan_category_id' => $category->id,
    'name'             => ['en' => 'Pro Monthly'],
    'billing_period'   => BillingPeriod::Monthly,
    'is_recurring'     => true,
    'is_active'        => true,
]);

$plan->items()->createMany([
    ['type' => LicenseType::Device->value,   'quantity' => 5,     'is_flexible' => false],
    ['type' => LicenseType::AiTokens->value, 'quantity' => 100000, 'is_flexible' => true],
    ['type' => LicenseType::Seat->value,     'quantity' => 10,    'is_flexible' => false],
]);
```

### 4. Assign a plan to a subscriber

```php
use LucaLongo\LaravelEntitlements\Facades\Entitlements;

$licenses = Entitlements::assignPlan(
    subscriber: $workspace,
    plan:       $plan,
    startsAt:   now(),
    quantityOverrides: [
        // Only flexible items accept overrides; keyed by PlanItem id
        $plan->items->firstWhere('is_flexible', true)->id => 500000,
    ],
);
```

Recurring plans produce licenses with `ends_at = null`. Fixed-term plans compute `ends_at` via `BillingPeriod::advance($startsAt)`.

### 5. Consume entitlements

```php
// Slot-based: one usage per subject
$usage = Entitlements::consume($workspace, LicenseType::Device, $device);

// Pool-based: drains the configured amount across one or more valid licenses
$usage = Entitlements::consume(
    $workspace,
    LicenseType::AiTokens,
    $aiUsage,
    amount: 1500,
);
```

If capacity is insufficient, `NoEntitlementAvailableException` is thrown.

### 6. Release entitlements

```php
// For two-phase SlotStrategy: request release (status -> Releasing), emits ReleaseRequested event
Entitlements::requestRelease($usage);

// When the external action completes (e.g. device confirms deactivation):
Entitlements::confirmRelease($usage);

// Force release from any state (admin override):
Entitlements::forceRelease($usage);
```

For single-phase strategies (`SlotStrategy(twoPhase: false)` or `PoolStrategy`) `requestRelease` and `confirmRelease` both release immediately.

### 7. Query availability

```php
Entitlements::available($workspace, LicenseType::AiTokens); // sum of remaining across valid licenses
Entitlements::capacity($workspace, LicenseType::AiTokens);  // sum of slot_total across valid licenses
Entitlements::can($workspace, LicenseType::AiTokens, 1500); // bool
```

### 8. Reconcile drifted counters

```php
// Recompute slot_used from open usages for a single license
Entitlements::reconcile($license);

// Reconcile every license owned by the subscriber
$result = Entitlements::recalculate($workspace);
// ['reconciled' => 7]
```

## Domain model

```
PlanCategory ──< Plan ──< PlanItem
                  │
                  └──< License (polymorphic subscriber) ──< LicenseUsage (polymorphic subject)
```

| Model           | Key columns                                                                                          |
|-----------------|------------------------------------------------------------------------------------------------------|
| `PlanCategory`  | `name` (translatable), `sort`                                                                        |
| `Plan`          | `plan_category_id`, `name` (translatable), `billing_period`, `is_recurring`, `is_active`             |
| `PlanItem`      | `plan_id`, `type`, `quantity`, `is_flexible`                                                         |
| `License`       | `subscriber_*` (morph), `plan_id`, `parent_id`, `type`, `slot_total`, `slot_used`, `starts_at`, `ends_at` |
| `LicenseUsage`  | `license_id`, `subject_*` (morph), `amount`, `status`                                                |

`License` exposes scopes `valid()` and `ofType(EntitlementType $type)`, plus a `remaining` accessor (`slot_total - slot_used`, floored at 0).

`LicenseUsage` exposes scope `open()` (not `Released`) and casts `status` to `LicenseUsageStatus`.

## Strategies

### SlotStrategy

```php
new SlotStrategy(twoPhase: false) // default
new SlotStrategy(twoPhase: true)
```

- `consume()` locks the oldest-expiring valid license with available capacity, creates a usage row with `amount = 1`, increments `slot_used`.
- `requestRelease()`:
  - single-phase: sets status to `Released`, decrements `slot_used`, fires `LicenseReleased`
  - two-phase: sets status to `Releasing`, fires `ReleaseRequested` (you typically dispatch an external job here)
- `confirmRelease()` (two-phase): sets status to `Released`, decrements `slot_used`, fires `LicenseReleased`
- `forceRelease()` releases from any state (admin override).

### PoolStrategy

- `consume(amount: N)` locks all valid licenses with capacity (ordered by expiration ascending, perpetual last), validates total availability, drains N across multiple licenses creating one usage row per license.
- All release methods are equivalent: they set the usage to `Released` and decrement the source license `slot_used` by the usage `amount`.
- `supportsTwoPhaseRelease()` returns `false`.

### Custom strategies

Implement the `EntitlementStrategy` contract:

```php
namespace LucaLongo\LaravelEntitlements\Contracts;

interface EntitlementStrategy
{
    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage;
    public function requestRelease(LicenseUsage $usage): void;
    public function confirmRelease(LicenseUsage $usage): void;
    public function forceRelease(LicenseUsage $usage): void;
    public function supportsTwoPhaseRelease(): bool;
}
```

Then return it from your enum's `strategy()` method.

## Events

| Event                | Payload                                                  | Fired when                                                  |
|----------------------|----------------------------------------------------------|-------------------------------------------------------------|
| `PlanAssigned`       | `Model $subscriber, Plan $plan, Collection $licenses`    | After `assignPlan()` creates the licenses                   |
| `LicenseConsumed`    | `LicenseUsage $usage`                                    | After a strategy creates an active usage row                |
| `ReleaseRequested`   | `LicenseUsage $usage`                                    | Two-phase release: status transitioned to `Releasing`       |
| `LicenseReleased`    | `LicenseUsage $usage`                                    | Final release: status transitioned to `Released`            |
| `LicenseReconciled`  | `License $license`                                       | After `reconcile()` recomputes the counter                  |

Hook your domain logic via standard Laravel listeners. The two-phase release flow is typically wired as: `ReleaseRequested → dispatch external job → on completion call `confirmRelease()`.

## Exceptions

- `NoEntitlementAvailableException` — thrown by strategies when capacity is insufficient
- `InvalidEntitlementTypeException` — thrown at boot if `config('entitlements.type_enum')` doesn't reference a valid backed enum implementing `EntitlementType`

## Filament integration (optional)

The package ships a Filament v5 plugin that exposes a Plans/Plan Categories admin UI and a `LicensesRelationManager` you can attach to your subscriber resource.

Install Filament v5 plus the two optional UI dependencies the resources rely on:

```bash
composer require filament/filament:^5.0 awcodes/filament-badgeable-column codewithdennis/filament-lucide-icons
```

Register the plugin on your panel:

```php
use LucaLongo\LaravelEntitlements\Filament\EntitlementsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(EntitlementsPlugin::make());
}
```

Opt out of either resource if you want to provide your own:

```php
EntitlementsPlugin::make()
    ->withoutPlanResource()
    ->withoutPlanCategoryResource();
```

Attach the `LicensesRelationManager` to the resource of your subscriber model (e.g. `WorkspaceResource`):

```php
use LucaLongo\LaravelEntitlements\Filament\RelationManagers\LicensesRelationManager;

public static function getRelations(): array
{
    return [LicensesRelationManager::class];
}
```

The relation manager provides three actions out of the box:

- **Assign Plan** — pick an active plan, set start/end dates, override quantities for flexible items
- **Recalculate Usages** — reconcile every license owned by the subscriber
- **Force Release Slot** — admin override for usages stuck in `Releasing` (two-phase strategies)

If the `type_enum` cases implement Filament's `HasLabel`, those labels are displayed in selects and badges; otherwise the enum case `name` is used as a fallback.

## Testing

```bash
composer test
```

The test suite runs against `:memory:` SQLite via Orchestra Testbench, with a workbench `TestType` enum that maps `Single → SlotStrategy(twoPhase: true)` and `Pooled → PoolStrategy`.

## Static analysis

```bash
composer analyse
```

PHPStan level configured via `phpstan.neon.dist`. The `src/Filament` directory is excluded by default since Filament is not a dev dependency; install it locally if you want to lint the plugin too.

## Code style

```bash
composer format
```

Runs Laravel Pint with the default preset.

## Credits

- [Luca Longo](https://github.com/masterix21)
- Domain logic extracted and generalized from the [Totem in Cloud](https://github.com/) production codebase.

## License

The MIT License (MIT). See [License File](LICENSE.md) for more information.
