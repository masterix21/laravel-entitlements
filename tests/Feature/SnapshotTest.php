<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Data\EntitlementSnapshot;
use LucaLongo\LaravelEntitlements\Data\EntitlementTypeUsage;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Facades\Entitlements;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanItem;
use Workbench\App\Enums\TestType;
use Workbench\App\Models\Subscriber;

beforeEach(function (): void {
    $this->subscriber = Subscriber::create(['name' => 'acme']);
});

it('returns one usage entry per configured enum case, including zero types', function (): void {
    $snapshot = Entitlements::snapshot($this->subscriber);

    expect($snapshot)->toBeInstanceOf(EntitlementSnapshot::class);
    expect($snapshot->types)->toHaveCount(count(TestType::cases()));
    expect($snapshot->types)->each->toBeInstanceOf(EntitlementTypeUsage::class);

    $byType = collect($snapshot->types)->keyBy(fn (EntitlementTypeUsage $u): string => $u->type->value);

    expect($byType[TestType::Single->value]->capacity)->toBe(0);
    expect($byType[TestType::Single->value]->used)->toBe(0);
    expect($byType[TestType::Single->value]->available)->toBe(0);
});

it('reports capacity, used and available after assignment and consumption', function (): void {
    $plan = Plan::factory()->create([
        'billing_period' => BillingPeriod::Monthly->value,
        'is_recurring' => true,
    ]);
    PlanItem::factory()->for($plan)->create(['type' => TestType::Pooled->value, 'quantity' => 10]);

    Entitlements::assignPlan($this->subscriber, $plan, now());
    Entitlements::consume($this->subscriber, TestType::Pooled, $this->subscriber, 3);

    $snapshot = Entitlements::snapshot($this->subscriber);
    $pooled = collect($snapshot->types)->firstWhere(fn (EntitlementTypeUsage $u): bool => $u->type === TestType::Pooled);

    expect($pooled->capacity)->toBe(10);
    expect($pooled->used)->toBe(3);
    expect($pooled->available)->toBe(7);
});

it('excludes expired licenses from the snapshot', function (): void {
    $plan = Plan::factory()->create([
        'billing_period' => BillingPeriod::Monthly->value,
        'is_recurring' => false,
    ]);
    PlanItem::factory()->for($plan)->create(['type' => TestType::Pooled->value, 'quantity' => 10]);

    Entitlements::assignPlan($this->subscriber, $plan, now()->subMonths(2));

    $snapshot = Entitlements::snapshot($this->subscriber);
    $pooled = collect($snapshot->types)->firstWhere(fn (EntitlementTypeUsage $u): bool => $u->type === TestType::Pooled);

    expect($pooled->capacity)->toBe(0);
    expect($pooled->available)->toBe(0);
});
