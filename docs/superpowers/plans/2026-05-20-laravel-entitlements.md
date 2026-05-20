# laravel-entitlements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `masterix21/laravel-entitlements` package: extract subscription plans + license business logic from `~/Dev/Sites/totem-in-cloud` into a reusable Laravel package where the project-specific entitlement type enum is injected via config + contract.

**Architecture:** MorphTo polymorphic owner (`subscriber`), strategy classes (`SlotStrategy`, `PoolStrategy`) mapped via a project-defined backed enum implementing `EntitlementType`. Service facade `Entitlements` orchestrates plan assignment, consumption, release, reconciliation. Two-phase release is opt-in per strategy.

**Tech Stack:** PHP ^8.4, Laravel 11/12/13, `spatie/laravel-package-tools`, `spatie/laravel-translatable`, Pest 4 + Orchestra Testbench, Larastan, Pint.

**Spec:** `docs/superpowers/specs/2026-05-20-laravel-entitlements-design.md`

**Conventions reminder:**
- Migrations have NO `down()` method
- All models `final`, typed properties, no docblocks where types suffice
- Early returns, no `else`
- Constructor property promotion when possible
- No comments unless WHY is non-obvious
- No reference to AI/Claude in commits

---

## File Structure

```
config/entitlements.php                                       # configured
database/migrations/
  create_entitlement_plan_categories_table.php.stub
  create_entitlement_plans_table.php.stub
  create_entitlement_plan_items_table.php.stub
  create_entitlement_licenses_table.php.stub
  create_entitlement_license_usages_table.php.stub
database/factories/
  PlanCategoryFactory.php
  PlanFactory.php
  PlanItemFactory.php
  LicenseFactory.php
  LicenseUsageFactory.php
src/
  LaravelEntitlementsServiceProvider.php                      # rewritten
  Entitlements.php                                            # service
  Facades/Entitlements.php
  Concerns/HasEntitlements.php
  Contracts/EntitlementType.php
  Contracts/EntitlementStrategy.php
  Enums/BillingPeriod.php
  Enums/LicenseUsageStatus.php
  Exceptions/NoEntitlementAvailableException.php
  Exceptions/InvalidEntitlementTypeException.php
  Models/PlanCategory.php
  Models/Plan.php
  Models/PlanItem.php
  Models/License.php
  Models/LicenseUsage.php
  Strategies/SlotStrategy.php
  Strategies/PoolStrategy.php
  Events/PlanAssigned.php
  Events/LicenseConsumed.php
  Events/ReleaseRequested.php
  Events/LicenseReleased.php
  Events/LicenseReconciled.php
workbench/
  app/Models/Subscriber.php
  app/Models/Subject.php
  app/Enums/TestType.php
  database/migrations/2026_01_01_000000_create_subscribers_and_subjects_tables.php
tests/
  TestCase.php                                                # modified
  Pest.php                                                    # modified
  Feature/PlanAssignmentTest.php
  Feature/SlotStrategyTest.php
  Feature/PoolStrategyTest.php
  Feature/ScopesTest.php
  Feature/ReconciliationTest.php
  Feature/EventsTest.php
  Feature/ConfigValidationTest.php
```

---

## Task 1: Bootstrap — composer dep + config + workbench DB

**Files:**
- Modify: `composer.json`
- Modify: `config/entitlements.php`
- Modify: `tests/TestCase.php`
- Create: `workbench/app/Models/Subscriber.php`
- Create: `workbench/app/Models/Subject.php`
- Create: `workbench/database/migrations/2026_01_01_000000_create_subscribers_and_subjects_tables.php`

- [ ] **Step 1: Add `spatie/laravel-translatable` to composer require**

In `composer.json`, replace the `require` block with:

```json
"require": {
    "php": "^8.4",
    "spatie/laravel-package-tools": "^1.16",
    "spatie/laravel-translatable": "^6.0",
    "illuminate/contracts": "^11.0||^12.0||^13.0"
},
```

Run: `composer update spatie/laravel-translatable --no-interaction`
Expected: spatie/laravel-translatable installed.

- [ ] **Step 2: Replace config skeleton**

Overwrite `config/entitlements.php`:

```php
<?php

declare(strict_types=1);

return [
    'type_enum' => null,

    'models' => [
        'plan_category' => \LucaLongo\LaravelEntitlements\Models\PlanCategory::class,
        'plan' => \LucaLongo\LaravelEntitlements\Models\Plan::class,
        'plan_item' => \LucaLongo\LaravelEntitlements\Models\PlanItem::class,
        'license' => \LucaLongo\LaravelEntitlements\Models\License::class,
        'license_usage' => \LucaLongo\LaravelEntitlements\Models\LicenseUsage::class,
    ],

    'table_names' => [
        'plan_categories' => 'entitlement_plan_categories',
        'plans' => 'entitlement_plans',
        'plan_items' => 'entitlement_plan_items',
        'licenses' => 'entitlement_licenses',
        'license_usages' => 'entitlement_license_usages',
    ],
];
```

- [ ] **Step 3: Create Subscriber workbench model**

Create `workbench/app/Models/Subscriber.php`:

```php
<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelEntitlements\Concerns\HasEntitlements;

final class Subscriber extends Model
{
    use HasEntitlements;

    protected $guarded = [];
}
```

- [ ] **Step 4: Create Subject workbench model**

Create `workbench/app/Models/Subject.php`:

```php
<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

final class Subject extends Model
{
    protected $guarded = [];
}
```

- [ ] **Step 5: Create workbench migration for subscribers + subjects**

Create `workbench/database/migrations/2026_01_01_000000_create_subscribers_and_subjects_tables.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }
};
```

- [ ] **Step 6: Modify `tests/TestCase.php` to load package + workbench migrations**

Replace with:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\File;
use LucaLongo\LaravelEntitlements\LaravelEntitlementsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'LucaLongo\\LaravelEntitlements\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelEntitlementsServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()->set('entitlements.type_enum', \Workbench\App\Enums\TestType::class);

        foreach (File::allFiles(__DIR__.'/../workbench/database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
        }

        foreach (File::allFiles(__DIR__.'/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
        }
    }
}
```

- [ ] **Step 7: Run tests to verify bootstrap (will fail on missing TestType — expected)**

Run: `composer test 2>&1 | tail -20`
Expected: error mentioning `Workbench\App\Enums\TestType` (will be created in Task 5).

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock config/entitlements.php tests/TestCase.php workbench/
git commit -m "chore: bootstrap entitlements package config and workbench fixtures"
```

---

## Task 2: Migrations (5 stubs)

**Files:**
- Create: `database/migrations/create_entitlement_plan_categories_table.php.stub`
- Create: `database/migrations/create_entitlement_plans_table.php.stub`
- Create: `database/migrations/create_entitlement_plan_items_table.php.stub`
- Create: `database/migrations/create_entitlement_licenses_table.php.stub`
- Create: `database/migrations/create_entitlement_license_usages_table.php.stub`

- [ ] **Step 1: Create plan_categories migration**

Create `database/migrations/create_entitlement_plan_categories_table.php.stub`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('entitlements.table_names.plan_categories'), function (Blueprint $table): void {
            $table->id();
            $table->text('name');
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }
};
```

- [ ] **Step 2: Create plans migration**

Create `database/migrations/create_entitlement_plans_table.php.stub`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('entitlements.table_names.plans'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_category_id')
                ->nullable()
                ->constrained(config('entitlements.table_names.plan_categories'))
                ->nullOnDelete();
            $table->text('name');
            $table->string('billing_period');
            $table->boolean('is_recurring')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
};
```

- [ ] **Step 3: Create plan_items migration**

Create `database/migrations/create_entitlement_plan_items_table.php.stub`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('entitlements.table_names.plan_items'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')
                ->constrained(config('entitlements.table_names.plans'))
                ->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('quantity');
            $table->boolean('is_flexible')->default(false);
            $table->timestamps();
        });
    }
};
```

- [ ] **Step 4: Create licenses migration**

Create `database/migrations/create_entitlement_licenses_table.php.stub`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('entitlements.table_names.licenses'), function (Blueprint $table): void {
            $table->id();
            $table->morphs('subscriber');
            $table->foreignId('plan_id')
                ->constrained(config('entitlements.table_names.plans'));
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained(config('entitlements.table_names.licenses'))
                ->nullOnDelete();
            $table->string('type');
            $table->unsignedInteger('slot_total');
            $table->unsignedInteger('slot_used')->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['subscriber_type', 'subscriber_id', 'type']);
            $table->index(['starts_at', 'ends_at']);
        });
    }
};
```

- [ ] **Step 5: Create license_usages migration**

Create `database/migrations/create_entitlement_license_usages_table.php.stub`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('entitlements.table_names.license_usages'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('license_id')
                ->constrained(config('entitlements.table_names.licenses'))
                ->cascadeOnDelete();
            $table->morphs('subject');
            $table->unsignedInteger('amount');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['license_id', 'status']);
        });
    }
};
```

- [ ] **Step 6: Commit**

```bash
git add database/migrations/
git commit -m "feat: add migration stubs for entitlement tables"
```

---

## Task 3: Contracts (EntitlementType, EntitlementStrategy)

**Files:**
- Create: `src/Contracts/EntitlementType.php`
- Create: `src/Contracts/EntitlementStrategy.php`

- [ ] **Step 1: Create EntitlementType contract**

Create `src/Contracts/EntitlementType.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Contracts;

use BackedEnum;

interface EntitlementType extends BackedEnum
{
    public function strategy(): EntitlementStrategy;
}
```

- [ ] **Step 2: Create EntitlementStrategy contract**

Create `src/Contracts/EntitlementStrategy.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Contracts;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

interface EntitlementStrategy
{
    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage;

    public function requestRelease(LicenseUsage $usage): void;

    public function confirmRelease(LicenseUsage $usage): void;

    public function forceRelease(LicenseUsage $usage): void;

    public function supportsTwoPhaseRelease(): bool;
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Contracts/
git commit -m "feat: add EntitlementType and EntitlementStrategy contracts"
```

---

## Task 4: Enums (BillingPeriod, LicenseUsageStatus)

**Files:**
- Create: `src/Enums/BillingPeriod.php`
- Create: `src/Enums/LicenseUsageStatus.php`
- Test: `tests/Feature/EnumsTest.php`

- [ ] **Step 1: Write failing test for BillingPeriod::advance**

Create `tests/Feature/EnumsTest.php`:

```php
<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;

it('advances a date by one month for Monthly', function (): void {
    $start = now()->startOfDay();

    $advanced = BillingPeriod::Monthly->advance($start);

    expect($advanced->diffInMonths($start))->toBe(1);
});

it('advances a date by one year for Yearly', function (): void {
    $start = now()->startOfDay();

    $advanced = BillingPeriod::Yearly->advance($start);

    expect($advanced->diffInYears($start))->toBe(1);
});

it('lists license usage statuses', function (): void {
    expect(LicenseUsageStatus::cases())->toHaveCount(3);
    expect(LicenseUsageStatus::Active->value)->toBe('active');
    expect(LicenseUsageStatus::Releasing->value)->toBe('releasing');
    expect(LicenseUsageStatus::Released->value)->toBe('released');
});
```

- [ ] **Step 2: Run test, expect FAIL (enums missing)**

Run: `composer test -- --filter=EnumsTest`
Expected: FAIL with class-not-found errors.

- [ ] **Step 3: Create BillingPeriod enum**

Create `src/Enums/BillingPeriod.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Enums;

use Carbon\CarbonInterface;

enum BillingPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function advance(CarbonInterface $date): CarbonInterface
    {
        return match ($this) {
            self::Monthly => $date->copy()->addMonthNoOverflow(),
            self::Yearly => $date->copy()->addYear(),
        };
    }
}
```

- [ ] **Step 4: Create LicenseUsageStatus enum**

Create `src/Enums/LicenseUsageStatus.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Enums;

enum LicenseUsageStatus: string
{
    case Active = 'active';
    case Releasing = 'releasing';
    case Released = 'released';
}
```

- [ ] **Step 5: Run test, expect PASS**

Run: `composer test -- --filter=EnumsTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Enums/ tests/Feature/EnumsTest.php
git commit -m "feat: add BillingPeriod and LicenseUsageStatus enums"
```

---

## Task 5: Workbench TestType enum + Exceptions

**Files:**
- Create: `workbench/app/Enums/TestType.php`
- Create: `src/Exceptions/NoEntitlementAvailableException.php`
- Create: `src/Exceptions/InvalidEntitlementTypeException.php`

Note: `TestType` references strategies that don't exist yet — we'll add the class declaration but it won't be instantiated until Task 9–10.

- [ ] **Step 1: Create stub strategies (empty classes — implementations come later)**

Create `src/Strategies/SlotStrategy.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Strategies;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class SlotStrategy implements EntitlementStrategy
{
    public function __construct(public readonly bool $twoPhase = false) {}

    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage
    {
        throw new \LogicException('SlotStrategy::consume not yet implemented');
    }

    public function requestRelease(LicenseUsage $usage): void
    {
        throw new \LogicException('SlotStrategy::requestRelease not yet implemented');
    }

    public function confirmRelease(LicenseUsage $usage): void
    {
        throw new \LogicException('SlotStrategy::confirmRelease not yet implemented');
    }

    public function forceRelease(LicenseUsage $usage): void
    {
        throw new \LogicException('SlotStrategy::forceRelease not yet implemented');
    }

    public function supportsTwoPhaseRelease(): bool
    {
        return $this->twoPhase;
    }
}
```

Create `src/Strategies/PoolStrategy.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Strategies;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class PoolStrategy implements EntitlementStrategy
{
    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage
    {
        throw new \LogicException('PoolStrategy::consume not yet implemented');
    }

    public function requestRelease(LicenseUsage $usage): void
    {
        $this->forceRelease($usage);
    }

    public function confirmRelease(LicenseUsage $usage): void
    {
        $this->forceRelease($usage);
    }

    public function forceRelease(LicenseUsage $usage): void
    {
        throw new \LogicException('PoolStrategy::forceRelease not yet implemented');
    }

    public function supportsTwoPhaseRelease(): bool
    {
        return false;
    }
}
```

- [ ] **Step 2: Create workbench TestType enum**

Create `workbench/app/Enums/TestType.php`:

```php
<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Strategies\PoolStrategy;
use LucaLongo\LaravelEntitlements\Strategies\SlotStrategy;

enum TestType: string implements EntitlementType
{
    case Single = 'single';
    case Pooled = 'pooled';

    public function strategy(): EntitlementStrategy
    {
        return match ($this) {
            self::Single => new SlotStrategy(twoPhase: true),
            self::Pooled => new PoolStrategy(),
        };
    }
}
```

- [ ] **Step 3: Create NoEntitlementAvailableException**

Create `src/Exceptions/NoEntitlementAvailableException.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use RuntimeException;

final class NoEntitlementAvailableException extends RuntimeException
{
    public static function forSubscriber(Model $subscriber, EntitlementType $type, int $requested): self
    {
        return new self(sprintf(
            'No entitlement available for subscriber [%s#%s] of type [%s] (requested: %d).',
            $subscriber::class,
            (string) $subscriber->getKey(),
            $type->value,
            $requested,
        ));
    }
}
```

- [ ] **Step 4: Create InvalidEntitlementTypeException**

Create `src/Exceptions/InvalidEntitlementTypeException.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use RuntimeException;

final class InvalidEntitlementTypeException extends RuntimeException
{
    public static function missing(): self
    {
        return new self('config("entitlements.type_enum") is not set.');
    }

    public static function invalid(string $class): self
    {
        return new self(sprintf(
            'Class [%s] must implement %s and be a backed enum.',
            $class,
            EntitlementType::class,
        ));
    }
}
```

- [ ] **Step 5: Commit**

```bash
git add src/Strategies/ src/Exceptions/ workbench/app/Enums/
git commit -m "feat: add strategy scaffolding, exceptions, and workbench TestType"
```

---

## Task 6: HasEntitlements trait + Models (PlanCategory, Plan, PlanItem)

**Files:**
- Create: `src/Concerns/HasEntitlements.php`
- Create: `src/Models/PlanCategory.php`
- Create: `src/Models/Plan.php`
- Create: `src/Models/PlanItem.php`
- Create: `database/factories/PlanCategoryFactory.php`
- Create: `database/factories/PlanFactory.php`
- Create: `database/factories/PlanItemFactory.php`

- [ ] **Step 1: Create HasEntitlements trait**

Create `src/Concerns/HasEntitlements.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use LucaLongo\LaravelEntitlements\Models\License;

trait HasEntitlements
{
    public function licenses(): MorphMany
    {
        return $this->morphMany(
            config('entitlements.models.license', License::class),
            'subscriber',
        );
    }
}
```

- [ ] **Step 2: Create PlanCategory model**

Create `src/Models/PlanCategory.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

final class PlanCategory extends Model
{
    use HasFactory;
    use HasTranslations;

    public array $translatable = ['name'];

    protected $guarded = [];

    public function getTable(): string
    {
        return config('entitlements.table_names.plan_categories');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(config('entitlements.models.plan', Plan::class));
    }

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }
}
```

- [ ] **Step 3: Create Plan model**

Create `src/Models/Plan.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use Spatie\Translatable\HasTranslations;

final class Plan extends Model
{
    use HasFactory;
    use HasTranslations;

    public array $translatable = ['name'];

    protected $guarded = [];

    public function getTable(): string
    {
        return config('entitlements.table_names.plans');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(
            config('entitlements.models.plan_category', PlanCategory::class),
            'plan_category_id',
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(config('entitlements.models.plan_item', PlanItem::class));
    }

    #[Scope]
    public function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    protected function casts(): array
    {
        return [
            'billing_period' => BillingPeriod::class,
            'is_recurring' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
```

- [ ] **Step 4: Create PlanItem model**

Create `src/Models/PlanItem.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LucaLongo\LaravelEntitlements\Exceptions\InvalidEntitlementTypeException;

final class PlanItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('entitlements.table_names.plan_items');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(config('entitlements.models.plan', Plan::class));
    }

    protected function casts(): array
    {
        $enum = config('entitlements.type_enum');

        if ($enum === null) {
            throw InvalidEntitlementTypeException::missing();
        }

        return [
            'quantity' => 'integer',
            'is_flexible' => 'boolean',
            'type' => $enum,
        ];
    }
}
```

- [ ] **Step 5: Create factories**

Create `database/factories/PlanCategoryFactory.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LucaLongo\LaravelEntitlements\Models\PlanCategory;

final class PlanCategoryFactory extends Factory
{
    protected $model = PlanCategory::class;

    public function definition(): array
    {
        return [
            'name' => ['en' => $this->faker->words(2, true)],
            'sort' => 0,
        ];
    }
}
```

Create `database/factories/PlanFactory.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Models\Plan;

final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'plan_category_id' => null,
            'name' => ['en' => $this->faker->words(2, true)],
            'billing_period' => BillingPeriod::Monthly->value,
            'is_recurring' => false,
            'is_active' => true,
        ];
    }
}
```

Create `database/factories/PlanItemFactory.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LucaLongo\LaravelEntitlements\Models\PlanItem;
use Workbench\App\Enums\TestType;

final class PlanItemFactory extends Factory
{
    protected $model = PlanItem::class;

    public function definition(): array
    {
        return [
            'type' => TestType::Single->value,
            'quantity' => 1,
            'is_flexible' => false,
        ];
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add src/Concerns/ src/Models/PlanCategory.php src/Models/Plan.php src/Models/PlanItem.php database/factories/
git commit -m "feat: add Plan, PlanCategory, PlanItem models and factories"
```

---

## Task 7: License and LicenseUsage models + factories

**Files:**
- Create: `src/Models/License.php`
- Create: `src/Models/LicenseUsage.php`
- Create: `database/factories/LicenseFactory.php`
- Create: `database/factories/LicenseUsageFactory.php`
- Test: `tests/Feature/ScopesTest.php`

- [ ] **Step 1: Write failing scope test**

Create `tests/Feature/ScopesTest.php`:

```php
<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;
use LucaLongo\LaravelEntitlements\Models\Plan;
use Workbench\App\Enums\TestType;
use Workbench\App\Models\Subscriber;
use Workbench\App\Models\Subject;

it('filters valid licenses by date window', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = Plan::factory()->create();

    $valid = License::create([
        'subscriber_type' => $subscriber->getMorphClass(),
        'subscriber_id' => $subscriber->id,
        'plan_id' => $plan->id,
        'type' => TestType::Single->value,
        'slot_total' => 5,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
    ]);

    License::create([
        'subscriber_type' => $subscriber->getMorphClass(),
        'subscriber_id' => $subscriber->id,
        'plan_id' => $plan->id,
        'type' => TestType::Single->value,
        'slot_total' => 5,
        'starts_at' => now()->addDay(),
        'ends_at' => null,
    ]);

    expect(License::query()->valid()->pluck('id')->all())->toBe([$valid->id]);
});

it('filters by type via ofType scope', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = Plan::factory()->create();

    License::create([
        'subscriber_type' => $subscriber->getMorphClass(),
        'subscriber_id' => $subscriber->id,
        'plan_id' => $plan->id,
        'type' => TestType::Single->value,
        'slot_total' => 1,
        'starts_at' => now(),
    ]);

    License::create([
        'subscriber_type' => $subscriber->getMorphClass(),
        'subscriber_id' => $subscriber->id,
        'plan_id' => $plan->id,
        'type' => TestType::Pooled->value,
        'slot_total' => 100,
        'starts_at' => now(),
    ]);

    expect(License::query()->ofType(TestType::Pooled)->count())->toBe(1);
});

it('filters open usages', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = Plan::factory()->create();
    $license = License::create([
        'subscriber_type' => $subscriber->getMorphClass(),
        'subscriber_id' => $subscriber->id,
        'plan_id' => $plan->id,
        'type' => TestType::Single->value,
        'slot_total' => 5,
        'starts_at' => now(),
    ]);
    $subject = Subject::create();

    LicenseUsage::create([
        'license_id' => $license->id,
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => $subject->id,
        'amount' => 1,
        'status' => LicenseUsageStatus::Active,
    ]);

    LicenseUsage::create([
        'license_id' => $license->id,
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => $subject->id,
        'amount' => 1,
        'status' => LicenseUsageStatus::Released,
    ]);

    expect(LicenseUsage::query()->open()->count())->toBe(1);
});

it('computes remaining attribute', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = Plan::factory()->create();

    $license = License::create([
        'subscriber_type' => $subscriber->getMorphClass(),
        'subscriber_id' => $subscriber->id,
        'plan_id' => $plan->id,
        'type' => TestType::Single->value,
        'slot_total' => 5,
        'slot_used' => 2,
        'starts_at' => now(),
    ]);

    expect($license->remaining)->toBe(3);
});
```

- [ ] **Step 2: Run test, expect FAIL (License/LicenseUsage missing)**

Run: `composer test -- --filter=ScopesTest`
Expected: FAIL.

- [ ] **Step 3: Create LicenseUsage model**

Create `src/Models/LicenseUsage.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;

final class LicenseUsage extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('entitlements.table_names.license_usages');
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(config('entitlements.models.license', License::class));
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    #[Scope]
    public function open(Builder $query): void
    {
        $query->where('status', '!=', LicenseUsageStatus::Released);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => LicenseUsageStatus::class,
        ];
    }
}
```

- [ ] **Step 4: Create License model**

Create `src/Models/License.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Exceptions\InvalidEntitlementTypeException;

final class License extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('entitlements.table_names.licenses');
    }

    public function subscriber(): MorphTo
    {
        return $this->morphTo();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(config('entitlements.models.plan', Plan::class));
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(config('entitlements.models.license_usage', LicenseUsage::class));
    }

    public function remaining(): Attribute
    {
        return Attribute::get(fn (): int => max(0, $this->slot_total - $this->slot_used));
    }

    public function isValid(): bool
    {
        $now = now();

        if ($this->starts_at->isAfter($now)) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isAfter($now);
    }

    #[Scope]
    public function valid(Builder $query): void
    {
        $now = now();

        $query->where('starts_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            });
    }

    #[Scope]
    public function ofType(Builder $query, EntitlementType $type): void
    {
        $query->where('type', $type->value);
    }

    protected function casts(): array
    {
        $enum = config('entitlements.type_enum');

        if ($enum === null) {
            throw InvalidEntitlementTypeException::missing();
        }

        return [
            'type' => $enum,
            'slot_total' => 'integer',
            'slot_used' => 'integer',
            'last_checked_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 5: Create factories for License and LicenseUsage**

Create `database/factories/LicenseFactory.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\Plan;
use Workbench\App\Enums\TestType;

final class LicenseFactory extends Factory
{
    protected $model = License::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'type' => TestType::Single->value,
            'slot_total' => 5,
            'slot_used' => 0,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ];
    }
}
```

Create `database/factories/LicenseUsageFactory.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class LicenseUsageFactory extends Factory
{
    protected $model = LicenseUsage::class;

    public function definition(): array
    {
        return [
            'amount' => 1,
            'status' => LicenseUsageStatus::Active,
        ];
    }
}
```

- [ ] **Step 6: Run scope test, expect PASS**

Run: `composer test -- --filter=ScopesTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Models/License.php src/Models/LicenseUsage.php database/factories/ tests/Feature/ScopesTest.php
git commit -m "feat: add License and LicenseUsage models with scopes"
```

---

## Task 8: Events

**Files:**
- Create: `src/Events/PlanAssigned.php`
- Create: `src/Events/LicenseConsumed.php`
- Create: `src/Events/ReleaseRequested.php`
- Create: `src/Events/LicenseReleased.php`
- Create: `src/Events/LicenseReconciled.php`

- [ ] **Step 1: Create PlanAssigned event**

Create `src/Events/PlanAssigned.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;
use LucaLongo\LaravelEntitlements\Models\Plan;

final class PlanAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly Model $subscriber,
        public readonly Plan $plan,
        public readonly Collection $licenses,
    ) {}
}
```

- [ ] **Step 2: Create LicenseConsumed event**

Create `src/Events/LicenseConsumed.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class LicenseConsumed
{
    use Dispatchable;

    public function __construct(public readonly LicenseUsage $usage) {}
}
```

- [ ] **Step 3: Create ReleaseRequested event**

Create `src/Events/ReleaseRequested.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class ReleaseRequested
{
    use Dispatchable;

    public function __construct(public readonly LicenseUsage $usage) {}
}
```

- [ ] **Step 4: Create LicenseReleased event**

Create `src/Events/LicenseReleased.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class LicenseReleased
{
    use Dispatchable;

    public function __construct(public readonly LicenseUsage $usage) {}
}
```

- [ ] **Step 5: Create LicenseReconciled event**

Create `src/Events/LicenseReconciled.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LucaLongo\LaravelEntitlements\Models\License;

final class LicenseReconciled
{
    use Dispatchable;

    public function __construct(public readonly License $license) {}
}
```

- [ ] **Step 6: Commit**

```bash
git add src/Events/
git commit -m "feat: add domain events for entitlements lifecycle"
```

---

## Task 9: SlotStrategy implementation (TDD)

**Files:**
- Modify: `src/Strategies/SlotStrategy.php`
- Test: `tests/Feature/SlotStrategyTest.php`

- [ ] **Step 1: Write failing SlotStrategy tests**

Create `tests/Feature/SlotStrategyTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;
use LucaLongo\LaravelEntitlements\Events\LicenseConsumed;
use LucaLongo\LaravelEntitlements\Events\LicenseReleased;
use LucaLongo\LaravelEntitlements\Events\ReleaseRequested;
use LucaLongo\LaravelEntitlements\Exceptions\NoEntitlementAvailableException;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Strategies\SlotStrategy;
use Workbench\App\Enums\TestType;
use Workbench\App\Models\Subject;
use Workbench\App\Models\Subscriber;

beforeEach(function (): void {
    $this->subscriber = Subscriber::create(['name' => 'acme']);
    $this->plan = Plan::factory()->create();
    $this->license = License::create([
        'subscriber_type' => $this->subscriber->getMorphClass(),
        'subscriber_id' => $this->subscriber->id,
        'plan_id' => $this->plan->id,
        'type' => TestType::Single->value,
        'slot_total' => 2,
        'slot_used' => 0,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addMonth(),
    ]);
});

it('consume creates usage and increments slot_used', function (): void {
    Event::fake([LicenseConsumed::class]);

    $subject = Subject::create();
    $strategy = new SlotStrategy();

    $usage = $strategy->consume($this->subscriber, TestType::Single, $subject);

    expect($usage->amount)->toBe(1);
    expect($usage->status)->toBe(LicenseUsageStatus::Active);
    expect($this->license->fresh()->slot_used)->toBe(1);
    Event::assertDispatched(LicenseConsumed::class);
});

it('consume throws when no slot available', function (): void {
    $this->license->update(['slot_used' => 2]);
    $strategy = new SlotStrategy();
    $subject = Subject::create();

    expect(fn () => $strategy->consume($this->subscriber, TestType::Single, $subject))
        ->toThrow(NoEntitlementAvailableException::class);
});

it('two-phase requestRelease keeps slot_used until confirm', function (): void {
    Event::fake([ReleaseRequested::class, LicenseReleased::class]);

    $strategy = new SlotStrategy(twoPhase: true);
    $usage = $strategy->consume($this->subscriber, TestType::Single, Subject::create());

    $strategy->requestRelease($usage);

    expect($usage->fresh()->status)->toBe(LicenseUsageStatus::Releasing);
    expect($this->license->fresh()->slot_used)->toBe(1);
    Event::assertDispatched(ReleaseRequested::class);
    Event::assertNotDispatched(LicenseReleased::class);

    $strategy->confirmRelease($usage);

    expect($usage->fresh()->status)->toBe(LicenseUsageStatus::Released);
    expect($this->license->fresh()->slot_used)->toBe(0);
    Event::assertDispatched(LicenseReleased::class);
});

it('single-phase requestRelease releases directly', function (): void {
    Event::fake([LicenseReleased::class]);

    $strategy = new SlotStrategy(twoPhase: false);
    $usage = $strategy->consume($this->subscriber, TestType::Single, Subject::create());

    $strategy->requestRelease($usage);

    expect($usage->fresh()->status)->toBe(LicenseUsageStatus::Released);
    expect($this->license->fresh()->slot_used)->toBe(0);
    Event::assertDispatched(LicenseReleased::class);
});

it('forceRelease releases from any state', function (): void {
    $strategy = new SlotStrategy(twoPhase: true);
    $usage = $strategy->consume($this->subscriber, TestType::Single, Subject::create());

    $strategy->forceRelease($usage);

    expect($usage->fresh()->status)->toBe(LicenseUsageStatus::Released);
    expect($this->license->fresh()->slot_used)->toBe(0);
});

it('forceRelease is idempotent when already released', function (): void {
    $strategy = new SlotStrategy();
    $usage = $strategy->consume($this->subscriber, TestType::Single, Subject::create());
    $strategy->forceRelease($usage);
    $beforeUsed = $this->license->fresh()->slot_used;

    $strategy->forceRelease($usage);

    expect($this->license->fresh()->slot_used)->toBe($beforeUsed);
});
```

- [ ] **Step 2: Run test, expect FAIL**

Run: `composer test -- --filter=SlotStrategyTest`
Expected: FAIL (LogicException from stub).

- [ ] **Step 3: Implement SlotStrategy**

Overwrite `src/Strategies/SlotStrategy.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;
use LucaLongo\LaravelEntitlements\Events\LicenseConsumed;
use LucaLongo\LaravelEntitlements\Events\LicenseReleased;
use LucaLongo\LaravelEntitlements\Events\ReleaseRequested;
use LucaLongo\LaravelEntitlements\Exceptions\NoEntitlementAvailableException;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class SlotStrategy implements EntitlementStrategy
{
    public function __construct(public readonly bool $twoPhase = false) {}

    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage
    {
        return DB::transaction(function () use ($subscriber, $type, $subject): LicenseUsage {
            $license = License::query()
                ->where('subscriber_type', $subscriber->getMorphClass())
                ->where('subscriber_id', $subscriber->getKey())
                ->valid()
                ->ofType($type)
                ->whereColumn('slot_used', '<', 'slot_total')
                ->orderByRaw('ends_at IS NULL, ends_at ASC')
                ->lockForUpdate()
                ->first();

            if ($license === null) {
                throw NoEntitlementAvailableException::forSubscriber($subscriber, $type, 1);
            }

            $usage = $license->usages()->create([
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'amount' => 1,
                'status' => LicenseUsageStatus::Active,
            ]);

            $license->increment('slot_used');

            LicenseConsumed::dispatch($usage);

            return $usage;
        });
    }

    public function requestRelease(LicenseUsage $usage): void
    {
        if ($usage->status === LicenseUsageStatus::Released) {
            return;
        }

        if (! $this->twoPhase) {
            $this->forceRelease($usage);

            return;
        }

        $usage->update(['status' => LicenseUsageStatus::Releasing]);

        ReleaseRequested::dispatch($usage);
    }

    public function confirmRelease(LicenseUsage $usage): void
    {
        if ($usage->status === LicenseUsageStatus::Released) {
            return;
        }

        $this->forceRelease($usage);
    }

    public function forceRelease(LicenseUsage $usage): void
    {
        if ($usage->status === LicenseUsageStatus::Released) {
            return;
        }

        DB::transaction(function () use ($usage): void {
            $usage->update(['status' => LicenseUsageStatus::Released]);

            License::query()
                ->whereKey($usage->license_id)
                ->where('slot_used', '>', 0)
                ->decrement('slot_used');
        });

        LicenseReleased::dispatch($usage);
    }

    public function supportsTwoPhaseRelease(): bool
    {
        return $this->twoPhase;
    }
}
```

- [ ] **Step 4: Run test, expect PASS**

Run: `composer test -- --filter=SlotStrategyTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Strategies/SlotStrategy.php tests/Feature/SlotStrategyTest.php
git commit -m "feat: implement SlotStrategy with optional two-phase release"
```

---

## Task 10: PoolStrategy implementation (TDD)

**Files:**
- Modify: `src/Strategies/PoolStrategy.php`
- Test: `tests/Feature/PoolStrategyTest.php`

- [ ] **Step 1: Write failing PoolStrategy tests**

Create `tests/Feature/PoolStrategyTest.php`:

```php
<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Exceptions\NoEntitlementAvailableException;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Strategies\PoolStrategy;
use Workbench\App\Enums\TestType;
use Workbench\App\Models\Subject;
use Workbench\App\Models\Subscriber;

beforeEach(function (): void {
    $this->subscriber = Subscriber::create(['name' => 'acme']);
    $this->plan = Plan::factory()->create();
});

function makePoolLicense(\Workbench\App\Models\Subscriber $subscriber, \LucaLongo\LaravelEntitlements\Models\Plan $plan, int $total, ?\Carbon\CarbonInterface $endsAt): License
{
    return License::create([
        'subscriber_type' => $subscriber->getMorphClass(),
        'subscriber_id' => $subscriber->id,
        'plan_id' => $plan->id,
        'type' => TestType::Pooled->value,
        'slot_total' => $total,
        'slot_used' => 0,
        'starts_at' => now()->subDay(),
        'ends_at' => $endsAt,
    ]);
}

it('consumes amount from a single license', function (): void {
    $license = makePoolLicense($this->subscriber, $this->plan, 100, now()->addMonth());
    $subject = Subject::create();
    $strategy = new PoolStrategy();

    $usage = $strategy->consume($this->subscriber, TestType::Pooled, $subject, amount: 30);

    expect($usage->amount)->toBe(30);
    expect($license->fresh()->slot_used)->toBe(30);
});

it('drains across multiple licenses ordered by ends_at ascending then nulls last', function (): void {
    $early = makePoolLicense($this->subscriber, $this->plan, 20, now()->addDay());
    $late = makePoolLicense($this->subscriber, $this->plan, 50, now()->addMonth());
    $perpetual = makePoolLicense($this->subscriber, $this->plan, 100, null);

    $strategy = new PoolStrategy();
    $strategy->consume($this->subscriber, TestType::Pooled, Subject::create(), amount: 60);

    expect($early->fresh()->slot_used)->toBe(20);
    expect($late->fresh()->slot_used)->toBe(40);
    expect($perpetual->fresh()->slot_used)->toBe(0);
});

it('throws when pool capacity is insufficient', function (): void {
    makePoolLicense($this->subscriber, $this->plan, 10, now()->addMonth());
    $strategy = new PoolStrategy();

    expect(fn () => $strategy->consume($this->subscriber, TestType::Pooled, Subject::create(), amount: 50))
        ->toThrow(NoEntitlementAvailableException::class);
});

it('forceRelease subtracts amount from license slot_used', function (): void {
    $license = makePoolLicense($this->subscriber, $this->plan, 100, now()->addMonth());
    $strategy = new PoolStrategy();
    $usage = $strategy->consume($this->subscriber, TestType::Pooled, Subject::create(), amount: 40);

    $strategy->forceRelease($usage);

    expect($license->fresh()->slot_used)->toBe(0);
});

it('supportsTwoPhaseRelease is false', function (): void {
    expect((new PoolStrategy())->supportsTwoPhaseRelease())->toBeFalse();
});
```

- [ ] **Step 2: Run test, expect FAIL**

Run: `composer test -- --filter=PoolStrategyTest`
Expected: FAIL.

- [ ] **Step 3: Implement PoolStrategy**

Overwrite `src/Strategies/PoolStrategy.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;
use LucaLongo\LaravelEntitlements\Events\LicenseConsumed;
use LucaLongo\LaravelEntitlements\Events\LicenseReleased;
use LucaLongo\LaravelEntitlements\Exceptions\NoEntitlementAvailableException;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class PoolStrategy implements EntitlementStrategy
{
    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        return DB::transaction(function () use ($subscriber, $type, $subject, $amount): LicenseUsage {
            $licenses = License::query()
                ->where('subscriber_type', $subscriber->getMorphClass())
                ->where('subscriber_id', $subscriber->getKey())
                ->valid()
                ->ofType($type)
                ->whereColumn('slot_used', '<', 'slot_total')
                ->orderByRaw('ends_at IS NULL, ends_at ASC')
                ->lockForUpdate()
                ->get();

            $available = $licenses->sum(fn (License $license): int => $license->remaining);

            if ($available < $amount) {
                throw NoEntitlementAvailableException::forSubscriber($subscriber, $type, $amount);
            }

            $remaining = $amount;
            $primaryUsage = null;

            foreach ($licenses as $license) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, $license->remaining);

                if ($take <= 0) {
                    continue;
                }

                $usage = $license->usages()->create([
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => $subject->getKey(),
                    'amount' => $take,
                    'status' => LicenseUsageStatus::Active,
                ]);

                $license->increment('slot_used', $take);
                $remaining -= $take;

                $primaryUsage ??= $usage;

                LicenseConsumed::dispatch($usage);
            }

            return $primaryUsage;
        });
    }

    public function requestRelease(LicenseUsage $usage): void
    {
        $this->forceRelease($usage);
    }

    public function confirmRelease(LicenseUsage $usage): void
    {
        $this->forceRelease($usage);
    }

    public function forceRelease(LicenseUsage $usage): void
    {
        if ($usage->status === LicenseUsageStatus::Released) {
            return;
        }

        DB::transaction(function () use ($usage): void {
            $amount = $usage->amount;
            $usage->update(['status' => LicenseUsageStatus::Released]);

            License::query()
                ->whereKey($usage->license_id)
                ->where('slot_used', '>=', $amount)
                ->decrement('slot_used', $amount);
        });

        LicenseReleased::dispatch($usage);
    }

    public function supportsTwoPhaseRelease(): bool
    {
        return false;
    }
}
```

- [ ] **Step 4: Run test, expect PASS**

Run: `composer test -- --filter=PoolStrategyTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Strategies/PoolStrategy.php tests/Feature/PoolStrategyTest.php
git commit -m "feat: implement PoolStrategy with FIFO drain across licenses"
```

---

## Task 11: Entitlements service + Facade (assignPlan + facade methods)

**Files:**
- Create: `src/Entitlements.php`
- Create: `src/Facades/Entitlements.php`
- Test: `tests/Feature/PlanAssignmentTest.php`

- [ ] **Step 1: Write failing PlanAssignment tests**

Create `tests/Feature/PlanAssignmentTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Events\PlanAssigned;
use LucaLongo\LaravelEntitlements\Facades\Entitlements;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanItem;
use Workbench\App\Enums\TestType;
use Workbench\App\Models\Subscriber;

beforeEach(function (): void {
    $this->subscriber = Subscriber::create(['name' => 'acme']);
});

it('assigns a non-recurring plan with computed ends_at', function (): void {
    Event::fake([PlanAssigned::class]);

    $plan = Plan::factory()->create([
        'billing_period' => BillingPeriod::Monthly->value,
        'is_recurring' => false,
    ]);

    PlanItem::factory()->for($plan)->create(['type' => TestType::Single->value, 'quantity' => 3]);
    PlanItem::factory()->for($plan)->create(['type' => TestType::Pooled->value, 'quantity' => 500]);

    $startsAt = now()->startOfDay();
    $licenses = Entitlements::assignPlan($this->subscriber, $plan, $startsAt);

    expect($licenses)->toHaveCount(2);
    expect($licenses->pluck('slot_total')->sort()->values()->all())->toBe([3, 500]);
    expect($licenses->every(fn ($l) => $l->ends_at !== null && $l->ends_at->equalTo($startsAt->copy()->addMonthNoOverflow())))->toBeTrue();
    Event::assertDispatched(PlanAssigned::class);
});

it('recurring plan creates licenses with null ends_at', function (): void {
    $plan = Plan::factory()->create([
        'billing_period' => BillingPeriod::Yearly->value,
        'is_recurring' => true,
    ]);

    PlanItem::factory()->for($plan)->create(['type' => TestType::Single->value, 'quantity' => 1]);

    $licenses = Entitlements::assignPlan($this->subscriber, $plan, now());

    expect($licenses->first()->ends_at)->toBeNull();
});

it('applies quantity overrides only to flexible items', function (): void {
    $plan = Plan::factory()->create();

    $fixed = PlanItem::factory()->for($plan)->create([
        'type' => TestType::Single->value,
        'quantity' => 5,
        'is_flexible' => false,
    ]);

    $flexible = PlanItem::factory()->for($plan)->create([
        'type' => TestType::Pooled->value,
        'quantity' => 100,
        'is_flexible' => true,
    ]);

    $licenses = Entitlements::assignPlan(
        $this->subscriber,
        $plan,
        now(),
        quantityOverrides: [$fixed->id => 999, $flexible->id => 250],
    );

    $byType = $licenses->keyBy(fn ($l) => $l->type->value);

    expect($byType[TestType::Single->value]->slot_total)->toBe(5);
    expect($byType[TestType::Pooled->value]->slot_total)->toBe(250);
});
```

- [ ] **Step 2: Run test, expect FAIL**

Run: `composer test -- --filter=PlanAssignmentTest`
Expected: FAIL (service/facade missing).

- [ ] **Step 3: Create Entitlements service**

Create `src/Entitlements.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Events\LicenseReconciled;
use LucaLongo\LaravelEntitlements\Events\PlanAssigned;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanItem;

final class Entitlements
{
    /**
     * @param  array<int, int>  $quantityOverrides  keyed by PlanItem id, applied only to flexible items
     * @return Collection<int, License>
     */
    public function assignPlan(Model $subscriber, Plan $plan, CarbonInterface $startsAt, array $quantityOverrides = []): Collection
    {
        $endsAt = $plan->is_recurring
            ? null
            : $plan->billing_period->advance($startsAt);

        $licenses = DB::transaction(function () use ($subscriber, $plan, $startsAt, $endsAt, $quantityOverrides): Collection {
            return $plan->items->map(fn (PlanItem $item): License => $this->createLicenseFromItem(
                $subscriber,
                $plan,
                $item,
                $startsAt,
                $endsAt,
                $quantityOverrides,
            ));
        });

        PlanAssigned::dispatch($subscriber, $plan, $licenses);

        return $licenses;
    }

    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage
    {
        return $type->strategy()->consume($subscriber, $type, $subject, $amount);
    }

    public function requestRelease(LicenseUsage $usage): void
    {
        $this->strategyFor($usage)->requestRelease($usage);
    }

    public function confirmRelease(LicenseUsage $usage): void
    {
        $this->strategyFor($usage)->confirmRelease($usage);
    }

    public function forceRelease(LicenseUsage $usage): void
    {
        $this->strategyFor($usage)->forceRelease($usage);
    }

    public function available(Model $subscriber, EntitlementType $type): int
    {
        return (int) License::query()
            ->where('subscriber_type', $subscriber->getMorphClass())
            ->where('subscriber_id', $subscriber->getKey())
            ->valid()
            ->ofType($type)
            ->get()
            ->sum(fn (License $license): int => $license->remaining);
    }

    public function capacity(Model $subscriber, EntitlementType $type): int
    {
        return (int) License::query()
            ->where('subscriber_type', $subscriber->getMorphClass())
            ->where('subscriber_id', $subscriber->getKey())
            ->valid()
            ->ofType($type)
            ->sum('slot_total');
    }

    public function can(Model $subscriber, EntitlementType $type, int $amount = 1): bool
    {
        return $this->available($subscriber, $type) >= $amount;
    }

    public function reconcile(License $license): void
    {
        $open = (int) $license->usages()->open()->sum('amount');

        $license->update([
            'slot_used' => $open,
            'last_checked_at' => now(),
        ]);

        LicenseReconciled::dispatch($license);
    }

    /**
     * @return array{reconciled: int}
     */
    public function recalculate(Model $subscriber): array
    {
        $licenses = License::query()
            ->where('subscriber_type', $subscriber->getMorphClass())
            ->where('subscriber_id', $subscriber->getKey())
            ->get();

        foreach ($licenses as $license) {
            $this->reconcile($license);
        }

        return ['reconciled' => $licenses->count()];
    }

    private function strategyFor(LicenseUsage $usage): \LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy
    {
        return $usage->license->type->strategy();
    }

    /**
     * @param  array<int, int>  $quantityOverrides
     */
    private function createLicenseFromItem(
        Model $subscriber,
        Plan $plan,
        PlanItem $item,
        CarbonInterface $startsAt,
        ?CarbonInterface $endsAt,
        array $quantityOverrides,
    ): License {
        $slotTotal = ($item->is_flexible && isset($quantityOverrides[$item->id]))
            ? (int) $quantityOverrides[$item->id]
            : $item->quantity;

        return License::create([
            'subscriber_type' => $subscriber->getMorphClass(),
            'subscriber_id' => $subscriber->getKey(),
            'plan_id' => $plan->id,
            'type' => $item->type->value,
            'slot_total' => $slotTotal,
            'slot_used' => 0,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
```

- [ ] **Step 4: Create Entitlements facade**

Create `src/Facades/Entitlements.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Facades;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Entitlements as EntitlementsService;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;
use LucaLongo\LaravelEntitlements\Models\Plan;

/**
 * @method static Collection<int, License> assignPlan(Model $subscriber, Plan $plan, CarbonInterface $startsAt, array $quantityOverrides = [])
 * @method static LicenseUsage consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1)
 * @method static void requestRelease(LicenseUsage $usage)
 * @method static void confirmRelease(LicenseUsage $usage)
 * @method static void forceRelease(LicenseUsage $usage)
 * @method static int available(Model $subscriber, EntitlementType $type)
 * @method static int capacity(Model $subscriber, EntitlementType $type)
 * @method static bool can(Model $subscriber, EntitlementType $type, int $amount = 1)
 * @method static void reconcile(License $license)
 * @method static array recalculate(Model $subscriber)
 */
final class Entitlements extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EntitlementsService::class;
    }
}
```

- [ ] **Step 5: Register service in ServiceProvider**

Overwrite `src/LaravelEntitlementsServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements;

use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Exceptions\InvalidEntitlementTypeException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class LaravelEntitlementsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-entitlements')
            ->hasConfigFile('entitlements')
            ->hasMigrations([
                'create_entitlement_plan_categories_table',
                'create_entitlement_plans_table',
                'create_entitlement_plan_items_table',
                'create_entitlement_licenses_table',
                'create_entitlement_license_usages_table',
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Entitlements::class);
    }

    public function packageBooted(): void
    {
        $this->validateTypeEnum();
    }

    private function validateTypeEnum(): void
    {
        $class = config('entitlements.type_enum');

        if ($class === null) {
            return;
        }

        if (! is_string($class) || ! enum_exists($class) || ! is_subclass_of($class, EntitlementType::class)) {
            throw InvalidEntitlementTypeException::invalid((string) $class);
        }
    }
}
```

- [ ] **Step 6: Update composer.json aliases**

In `composer.json` replace the `extra.laravel.aliases` block:

```json
"aliases": {
    "Entitlements": "LucaLongo\\LaravelEntitlements\\Facades\\Entitlements"
}
```

Run: `composer dump-autoload`

- [ ] **Step 7: Run test, expect PASS**

Run: `composer test -- --filter=PlanAssignmentTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Entitlements.php src/Facades/ src/LaravelEntitlementsServiceProvider.php composer.json tests/Feature/PlanAssignmentTest.php
git commit -m "feat: add Entitlements service, facade, and provider wiring"
```

---

## Task 12: Reconciliation + capacity/available/can tests

**Files:**
- Test: `tests/Feature/ReconciliationTest.php`

- [ ] **Step 1: Write reconciliation and capacity tests**

Create `tests/Feature/ReconciliationTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use LucaLongo\LaravelEntitlements\Events\LicenseReconciled;
use LucaLongo\LaravelEntitlements\Facades\Entitlements;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\Plan;
use Workbench\App\Enums\TestType;
use Workbench\App\Models\Subject;
use Workbench\App\Models\Subscriber;

beforeEach(function (): void {
    $this->subscriber = Subscriber::create(['name' => 'acme']);
    $this->plan = Plan::factory()->create();
});

it('reconcile recomputes slot_used from open usages', function (): void {
    Event::fake([LicenseReconciled::class]);

    $license = License::create([
        'subscriber_type' => $this->subscriber->getMorphClass(),
        'subscriber_id' => $this->subscriber->id,
        'plan_id' => $this->plan->id,
        'type' => TestType::Pooled->value,
        'slot_total' => 100,
        'slot_used' => 99,
        'starts_at' => now()->subDay(),
    ]);

    Entitlements::consume($this->subscriber, TestType::Pooled, Subject::create(), amount: 10);

    Entitlements::reconcile($license);

    expect($license->fresh()->slot_used)->toBe(10);
    expect($license->fresh()->last_checked_at)->not->toBeNull();
    Event::assertDispatched(LicenseReconciled::class);
});

it('available returns sum of remaining', function (): void {
    License::create([
        'subscriber_type' => $this->subscriber->getMorphClass(),
        'subscriber_id' => $this->subscriber->id,
        'plan_id' => $this->plan->id,
        'type' => TestType::Pooled->value,
        'slot_total' => 100,
        'slot_used' => 30,
        'starts_at' => now()->subDay(),
    ]);

    License::create([
        'subscriber_type' => $this->subscriber->getMorphClass(),
        'subscriber_id' => $this->subscriber->id,
        'plan_id' => $this->plan->id,
        'type' => TestType::Pooled->value,
        'slot_total' => 50,
        'slot_used' => 10,
        'starts_at' => now()->subDay(),
    ]);

    expect(Entitlements::available($this->subscriber, TestType::Pooled))->toBe(110);
    expect(Entitlements::capacity($this->subscriber, TestType::Pooled))->toBe(150);
    expect(Entitlements::can($this->subscriber, TestType::Pooled, 110))->toBeTrue();
    expect(Entitlements::can($this->subscriber, TestType::Pooled, 111))->toBeFalse();
});

it('recalculate iterates over all subscriber licenses', function (): void {
    License::factory()->count(3)->create([
        'subscriber_type' => $this->subscriber->getMorphClass(),
        'subscriber_id' => $this->subscriber->id,
        'plan_id' => $this->plan->id,
    ]);

    $result = Entitlements::recalculate($this->subscriber);

    expect($result)->toBe(['reconciled' => 3]);
});
```

- [ ] **Step 2: Run test, expect PASS**

Run: `composer test -- --filter=ReconciliationTest`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/ReconciliationTest.php
git commit -m "test: add reconciliation, capacity, and recalculate coverage"
```

---

## Task 13: Config validation test

**Files:**
- Test: `tests/Feature/ConfigValidationTest.php`

- [ ] **Step 1: Write config validation test**

Create `tests/Feature/ConfigValidationTest.php`:

```php
<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Exceptions\InvalidEntitlementTypeException;
use LucaLongo\LaravelEntitlements\LaravelEntitlementsServiceProvider;
use Orchestra\Testbench\Factories\UserFactory;

it('throws when type_enum is set to a non-enum class', function (): void {
    config()->set('entitlements.type_enum', UserFactory::class);

    $provider = new LaravelEntitlementsServiceProvider($this->app);

    expect(fn () => $provider->packageBooted())
        ->toThrow(InvalidEntitlementTypeException::class);
});

it('throws when type_enum is set to an enum that does not implement EntitlementType', function (): void {
    config()->set('entitlements.type_enum', \LucaLongo\LaravelEntitlements\Enums\BillingPeriod::class);

    $provider = new LaravelEntitlementsServiceProvider($this->app);

    expect(fn () => $provider->packageBooted())
        ->toThrow(InvalidEntitlementTypeException::class);
});

it('passes validation for a valid EntitlementType enum', function (): void {
    config()->set('entitlements.type_enum', \Workbench\App\Enums\TestType::class);

    $provider = new LaravelEntitlementsServiceProvider($this->app);

    $provider->packageBooted();

    expect(true)->toBeTrue();
});
```

- [ ] **Step 2: Run test, expect PASS**

Run: `composer test -- --filter=ConfigValidationTest`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/ConfigValidationTest.php
git commit -m "test: validate config('entitlements.type_enum') at boot"
```

---

## Task 14: Full suite, static analysis, format, README example

**Files:**
- Modify: `README.md` (usage section)

- [ ] **Step 1: Run the full test suite**

Run: `composer test`
Expected: all tests PASS.

- [ ] **Step 2: Run Pint**

Run: `composer format`
Expected: code formatted; commit any diffs.

- [ ] **Step 3: Run Larastan**

Run: `composer analyse`
Expected: 0 errors. If new errors surface in the new code, fix them inline; do NOT add them to `phpstan-baseline.neon`.

- [ ] **Step 4: Add usage section to README**

Open `README.md` and append the following section before the existing `## Testing` / `## Credits` sections (place after the install section):

```markdown
## Usage

Define a backed enum in your application that implements `EntitlementType`:

```php
use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Strategies\PoolStrategy;
use LucaLongo\LaravelEntitlements\Strategies\SlotStrategy;

enum LicenseType: string implements EntitlementType
{
    case Device = 'device';
    case AiTokens = 'ai_tokens';

    public function strategy(): EntitlementStrategy
    {
        return match ($this) {
            self::Device => new SlotStrategy(twoPhase: true),
            self::AiTokens => new PoolStrategy(),
        };
    }
}
```

Then set it in `config/entitlements.php`:

```php
'type_enum' => \App\Enums\LicenseType::class,
```

Add the trait to the model that owns licenses:

```php
use LucaLongo\LaravelEntitlements\Concerns\HasEntitlements;

class Workspace extends Model
{
    use HasEntitlements;
}
```

Use the facade:

```php
use LucaLongo\LaravelEntitlements\Facades\Entitlements;

Entitlements::assignPlan($workspace, $plan, now());
Entitlements::consume($workspace, LicenseType::Device, $device);
Entitlements::requestRelease($usage);  // ReleaseRequested event fires
Entitlements::confirmRelease($usage);
Entitlements::consume($workspace, LicenseType::AiTokens, $aiUsage, amount: 1500);
```
```

- [ ] **Step 5: Commit final changes**

```bash
git add README.md
git commit -m "docs: add usage section to README"
```

- [ ] **Step 6: Final verification**

Run: `composer test && composer analyse && composer format`
Expected: all green.

---

## Self-review notes

Coverage of spec sections:

| Spec section | Task(s) |
|---|---|
| §2 Scope (in/out) | Whole plan; out-of-scope items are absent (DeviceCommand, canUseAi, Filament) |
| §3 Architectural decisions | Task 6, 7 (morph + table prefix + translatable), Task 4 (no HasLabel), Task 9 (two-phase opt-in) |
| §4 Schema (5 tables + indexes) | Task 2 |
| §5 Models | Task 6, 7 |
| §5 Enums | Task 4 |
| §5 Contracts | Task 3 |
| §5 Strategies | Task 9 (Slot), 10 (Pool) |
| §5 Service + Facade | Task 11 (assignPlan, consume, release*, available, capacity, can), Task 12 (reconcile, recalculate) |
| §5 Trait HasEntitlements | Task 6 |
| §5 Events | Task 8 (created), wired in 9/10/11/12 |
| §5 Exceptions | Task 5 |
| §6 ServiceProvider | Task 11 |
| §7 Config | Task 1, validation in Task 13 |
| §8 Tests | Tasks 4, 7, 9, 10, 11, 12, 13 (EnumsTest, ScopesTest, SlotStrategyTest, PoolStrategyTest, PlanAssignmentTest, ReconciliationTest, ConfigValidationTest) |
| §9 Composer deps | Task 1 |
| §11 README example | Task 14 |

No `EventsTest.php` standalone file (per spec §8 item 6): event dispatch is asserted inline within SlotStrategyTest, PlanAssignmentTest, and ReconciliationTest using `Event::fake`. This is more focused than a separate file and keeps assertions next to behavior.
