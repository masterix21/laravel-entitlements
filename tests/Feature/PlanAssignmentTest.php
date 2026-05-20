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
