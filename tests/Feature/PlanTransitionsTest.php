<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Enums\PlanTransitionMode;
use LucaLongo\LaravelEntitlements\Enums\PlanTransitionStatus;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanCategory;
use LucaLongo\LaravelEntitlements\Models\PlanTransition;
use Workbench\App\Enums\TestType;
use Workbench\App\Models\Subscriber;

it('casts allows_multiple_active_plans to boolean and defaults to true', function (): void {
    $category = PlanCategory::create(['name' => 'Subscriptions']);

    expect($category->allows_multiple_active_plans)->toBeTrue();

    $exclusive = PlanCategory::create(['name' => 'Exclusive', 'allows_multiple_active_plans' => false]);
    expect($exclusive->allows_multiple_active_plans)->toBeFalse();
});

it('persists a plan transition with casts and relations', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = Plan::factory()->create();
    $anchor = License::create([
        'subscriber_type' => $subscriber->getMorphClass(),
        'subscriber_id' => $subscriber->getKey(),
        'plan_id' => $plan->id,
        'type' => TestType::Single->value,
        'slot_total' => 1,
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);
    $target = Plan::factory()->create();

    $transition = PlanTransition::create([
        'anchor_license_id' => $anchor->id,
        'target_plan_id' => $target->id,
        'apply_mode' => PlanTransitionMode::EndOfPeriod->value,
        'status' => PlanTransitionStatus::Pending->value,
        'scheduled_at' => now()->addDay(),
        'quantity_overrides' => [1 => 50],
    ]);

    expect($transition->apply_mode)->toBe(PlanTransitionMode::EndOfPeriod)
        ->and($transition->status)->toBe(PlanTransitionStatus::Pending)
        ->and($transition->quantity_overrides)->toBe([1 => 50])
        ->and($transition->anchorLicense->is($anchor))->toBeTrue()
        ->and($transition->targetPlan->is($target))->toBeTrue();
});

it('scopes pending and due transitions', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = Plan::factory()->create();
    $anchor = License::create([
        'subscriber_type' => $subscriber->getMorphClass(),
        'subscriber_id' => $subscriber->getKey(),
        'plan_id' => $plan->id,
        'type' => TestType::Single->value,
        'slot_total' => 1,
        'starts_at' => now(),
        'ends_at' => now()->addMonth(),
    ]);

    $pendingDue = PlanTransition::create([
        'anchor_license_id' => $anchor->id,
        'target_plan_id' => $plan->id,
        'apply_mode' => 'end_of_period',
        'status' => 'pending',
        'scheduled_at' => now()->subMinute(),
    ]);

    PlanTransition::create([
        'anchor_license_id' => $anchor->id,
        'target_plan_id' => $plan->id,
        'apply_mode' => 'end_of_period',
        'status' => 'pending',
        'scheduled_at' => now()->addDay(),
    ]);

    PlanTransition::create([
        'anchor_license_id' => $anchor->id,
        'target_plan_id' => $plan->id,
        'apply_mode' => 'immediate',
        'status' => 'applied',
        'scheduled_at' => now()->subMinute(),
        'applied_at' => now(),
    ]);

    expect(PlanTransition::pending()->count())->toBe(2);
    expect(PlanTransition::due()->pluck('id')->all())->toBe([$pendingDue->id]);
});
