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

use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Exceptions\AnchorNotActiveForTransition;
use LucaLongo\LaravelEntitlements\Exceptions\IncompatiblePlanTransition;
use LucaLongo\LaravelEntitlements\Exceptions\InsufficientCapacityForTransition;
use LucaLongo\LaravelEntitlements\Exceptions\InvalidTransitionScheduledDate;
use LucaLongo\LaravelEntitlements\Exceptions\NoOpPlanTransition;
use LucaLongo\LaravelEntitlements\Exceptions\PlanCategoryExclusivityViolation;
use LucaLongo\LaravelEntitlements\Facades\Entitlements;
use LucaLongo\LaravelEntitlements\Models\PlanItem;

function makePlan(array $items = [], ?int $categoryId = null): Plan
{
    $plan = Plan::factory()->create([
        'billing_period' => BillingPeriod::Monthly->value,
        'is_recurring' => false,
        'plan_category_id' => $categoryId,
    ]);
    foreach ($items as $row) {
        PlanItem::factory()->for($plan)->create($row);
    }

    return $plan;
}

it('rejects transition when anchor is not actually an anchor', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $licenses = Entitlements::assignPlan($subscriber, $plan, now());
    $child = $licenses->firstWhere('parent_id', '!=', null) ?? $licenses->skip(1)->first();

    if ($child === null) {
        $child = License::create([
            'subscriber_type' => $subscriber->getMorphClass(),
            'subscriber_id' => $subscriber->getKey(),
            'plan_id' => $plan->id,
            'parent_id' => $licenses->first()->id,
            'type' => TestType::Single->value,
            'slot_total' => 1,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);

    Entitlements::changePlan($child, $target, PlanTransitionMode::Immediate);
})->throws(AnchorNotActiveForTransition::class);

it('rejects transition when anchor has already expired', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now()->subMonths(2))->first();
    $anchor->update(['ends_at' => now()->subDay()]);

    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);

    Entitlements::changePlan($anchor->fresh(), $target, PlanTransitionMode::Immediate);
})->throws(AnchorNotActiveForTransition::class);

it('schedules end-of-period on a perpetual plan using the next billing date', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = Plan::factory()->create([
        'billing_period' => BillingPeriod::Monthly->value,
        'is_recurring' => true,
    ]);
    PlanItem::factory()->for($plan)->create(['type' => TestType::Single->value, 'quantity' => 1]);
    $startsAt = now()->startOfDay();
    $anchor = Entitlements::assignPlan($subscriber, $plan, $startsAt)->first();

    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);

    $transition = Entitlements::changePlan($anchor, $target, PlanTransitionMode::EndOfPeriod);

    expect($transition->scheduled_at->equalTo($startsAt->copy()->addMonthNoOverflow()))->toBeTrue();
});

it('accepts at-date mode with a future scheduled date', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();

    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $when = now()->addDays(10)->startOfSecond();

    $transition = Entitlements::changePlan($anchor, $target, PlanTransitionMode::AtDate, [], $when);

    expect($transition->scheduled_at->timestamp)->toBe($when->timestamp)
        ->and($transition->status->value)->toBe('pending');
});

it('rejects at-date mode when no date is provided', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);

    Entitlements::changePlan($anchor, $target, PlanTransitionMode::AtDate);
})->throws(InvalidTransitionScheduledDate::class);

it('rejects at-date mode when date is in the past', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);

    Entitlements::changePlan($anchor, $target, PlanTransitionMode::AtDate, [], now()->subDay());
})->throws(InvalidTransitionScheduledDate::class);

it('rejects transition when an open usage type is missing in the new plan', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([
        ['type' => TestType::Single->value, 'quantity' => 1],
        ['type' => TestType::Pooled->value, 'quantity' => 10],
    ]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    Entitlements::consume($subscriber, TestType::Pooled, $subscriber, 1);

    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);

    Entitlements::changePlan($anchor, $target, PlanTransitionMode::Immediate);
})->throws(IncompatiblePlanTransition::class);

it('rejects transition when target capacity is below current usage', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Pooled->value, 'quantity' => 10]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    Entitlements::consume($subscriber, TestType::Pooled, $subscriber, 5);

    $target = makePlan([['type' => TestType::Pooled->value, 'quantity' => 2]]);

    Entitlements::changePlan($anchor, $target, PlanTransitionMode::Immediate);
})->throws(InsufficientCapacityForTransition::class);

it('rejects transition that would violate category exclusivity', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $category = PlanCategory::create(['name' => 'Exclusive', 'allows_multiple_active_plans' => true]);

    $planA = makePlan([['type' => TestType::Single->value, 'quantity' => 1]], $category->id);
    $planB = makePlan([['type' => TestType::Single->value, 'quantity' => 1]], $category->id);
    $planC = makePlan([['type' => TestType::Single->value, 'quantity' => 1]], $category->id);

    $anchorA = Entitlements::assignPlan($subscriber, $planA, now())->first();
    Entitlements::assignPlan($subscriber, $planB, now())->first();

    $category->update(['allows_multiple_active_plans' => false]);

    Entitlements::changePlan($anchorA, $planC, PlanTransitionMode::Immediate);
})->throws(PlanCategoryExclusivityViolation::class);

it('rejects a no-op plan change (same plan and quantities)', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([
        ['type' => TestType::Single->value, 'quantity' => 1],
        ['type' => TestType::Pooled->value, 'quantity' => 50, 'is_flexible' => true],
    ]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now(), [
        $plan->items->firstWhere('type', TestType::Pooled)->id => 100,
    ])->first();

    Entitlements::changePlan($anchor, $plan, PlanTransitionMode::Immediate, [
        $plan->items->firstWhere('type', TestType::Pooled)->id => 100,
    ]);
})->throws(NoOpPlanTransition::class);

it('allows changing only quantities on the same plan', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([
        ['type' => TestType::Pooled->value, 'quantity' => 50, 'is_flexible' => true],
    ]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();

    $transition = Entitlements::changePlan($anchor, $plan, PlanTransitionMode::Immediate, [
        $plan->items->first()->id => 75,
    ]);

    expect($transition->status->value)->toBe('applied');
});

it('rejects assignPlan when category disallows multiple active plans and one already exists', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $category = PlanCategory::create(['name' => 'Exclusive', 'allows_multiple_active_plans' => false]);

    $planA = makePlan([['type' => TestType::Single->value, 'quantity' => 1]], $category->id);
    $planB = makePlan([['type' => TestType::Single->value, 'quantity' => 1]], $category->id);

    Entitlements::assignPlan($subscriber, $planA, now());

    Entitlements::assignPlan($subscriber, $planB, now());
})->throws(PlanCategoryExclusivityViolation::class);

it('allows assignPlan in exclusive category when no active plan exists', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $category = PlanCategory::create(['name' => 'Exclusive', 'allows_multiple_active_plans' => false]);

    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]], $category->id);

    $licenses = Entitlements::assignPlan($subscriber, $plan, now());

    expect($licenses)->toHaveCount(1);
});

it('re-reads the category exclusivity flag from the database before enforcing', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $category = PlanCategory::create(['name' => 'Exclusive', 'allows_multiple_active_plans' => false]);

    $planA = makePlan([['type' => TestType::Single->value, 'quantity' => 1]], $category->id);
    $planB = makePlan([['type' => TestType::Single->value, 'quantity' => 1]], $category->id);

    Entitlements::assignPlan($subscriber, $planA, now());

    $planB->load('category');

    // Concurrent toggle: the stale relation still says exclusive.
    PlanCategory::query()->whereKey($category->id)->update(['allows_multiple_active_plans' => true]);

    $licenses = Entitlements::assignPlan($subscriber, $planB, now());

    expect($licenses)->toHaveCount(1);
});

it('creates no licenses when assignPlan is rejected for exclusivity', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $category = PlanCategory::create(['name' => 'Exclusive', 'allows_multiple_active_plans' => false]);

    $planA = makePlan([['type' => TestType::Single->value, 'quantity' => 1]], $category->id);
    $planB = makePlan([
        ['type' => TestType::Single->value, 'quantity' => 1],
        ['type' => TestType::Pooled->value, 'quantity' => 10],
    ], $category->id);

    Entitlements::assignPlan($subscriber, $planA, now());
    $licenseCount = License::query()->count();

    expect(fn () => Entitlements::assignPlan($subscriber, $planB, now()))
        ->toThrow(PlanCategoryExclusivityViolation::class);

    expect(License::query()->count())->toBe($licenseCount);
});

use Illuminate\Support\Facades\Event;
use LucaLongo\LaravelEntitlements\Events\PlanTransitionApplied;
use LucaLongo\LaravelEntitlements\Events\PlanTransitionScheduled;

it('applies an immediate transition: closes old group, creates new, migrates usages', function (): void {
    Event::fake([PlanTransitionApplied::class]);

    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([
        ['type' => TestType::Single->value, 'quantity' => 1],
        ['type' => TestType::Pooled->value, 'quantity' => 100],
    ]);
    $oldAnchor = Entitlements::assignPlan($subscriber, $plan, now())->firstWhere('parent_id', null);
    $usage = Entitlements::consume($subscriber, TestType::Pooled, $subscriber, 5);

    $target = makePlan([
        ['type' => TestType::Single->value, 'quantity' => 2],
        ['type' => TestType::Pooled->value, 'quantity' => 200],
    ]);

    $transition = Entitlements::changePlan($oldAnchor, $target, PlanTransitionMode::Immediate);

    expect($transition->fresh()->status)->toBe(PlanTransitionStatus::Applied);
    expect($transition->fresh()->new_anchor_license_id)->not->toBeNull();

    $newAnchor = $transition->fresh()->newAnchorLicense;
    expect($newAnchor->plan_id)->toBe($target->id)
        ->and($newAnchor->parent_id)->toBeNull();

    expect($oldAnchor->fresh()->ends_at)->not->toBeNull();
    expect($oldAnchor->fresh()->ends_at->lessThanOrEqualTo(now()))->toBeTrue();

    expect($usage->fresh()->license->plan_id)->toBe($target->id);
    expect($usage->fresh()->license->type->value)->toBe(TestType::Pooled->value);

    Event::assertDispatched(PlanTransitionApplied::class);
});

it('schedules an end-of-period transition without altering the current group', function (): void {
    Event::fake([PlanTransitionScheduled::class, PlanTransitionApplied::class]);

    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $endsAt = $anchor->ends_at;

    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);

    $transition = Entitlements::changePlan($anchor, $target, PlanTransitionMode::EndOfPeriod);

    expect($transition->status)->toBe(PlanTransitionStatus::Pending)
        ->and($transition->scheduled_at->equalTo($endsAt))->toBeTrue();

    expect($anchor->fresh()->ends_at->equalTo($endsAt))->toBeTrue();

    Event::assertDispatched(PlanTransitionScheduled::class);
    Event::assertNotDispatched(PlanTransitionApplied::class);
});

it('materializes due transitions via applyDueTransitions', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $endsAt = $anchor->ends_at;

    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $transition = Entitlements::changePlan($anchor, $target, PlanTransitionMode::EndOfPeriod);

    $this->travelTo($endsAt->copy()->addSecond());

    $applied = Entitlements::applyDueTransitions();

    expect($applied)->toBe(1);
    expect($transition->fresh()->status)->toBe(PlanTransitionStatus::Applied);
    expect($anchor->fresh()->ends_at->lessThanOrEqualTo(now()))->toBeTrue();
});

it('migrates usages consumed between scheduling and apply', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Pooled->value, 'quantity' => 10]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $endsAt = $anchor->ends_at;

    $target = makePlan([['type' => TestType::Pooled->value, 'quantity' => 20]]);
    $transition = Entitlements::changePlan($anchor, $target, PlanTransitionMode::EndOfPeriod);

    $usage = Entitlements::consume($subscriber, TestType::Pooled, $subscriber, 4);

    $this->travelTo($endsAt->copy()->addSecond());
    expect(Entitlements::applyDueTransitions())->toBe(1);

    $newAnchor = $transition->fresh()->newAnchorLicense;

    expect($usage->fresh()->license_id)->toBe($newAnchor->id)
        ->and($newAnchor->fresh()->slot_used)->toBe(4)
        ->and($anchor->fresh()->ends_at->lessThanOrEqualTo(now()))->toBeTrue();
});

it('runs entitlements:apply-transitions command', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $endsAt = $anchor->ends_at;
    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $transition = Entitlements::changePlan($anchor, $target, PlanTransitionMode::EndOfPeriod);

    $this->travelTo($endsAt->copy()->addSecond());

    $this->artisan('entitlements:apply-transitions')->assertSuccessful();

    expect($transition->fresh()->status)->toBe(PlanTransitionStatus::Applied);
});

use LucaLongo\LaravelEntitlements\Events\PlanTransitionCancelled;
use LucaLongo\LaravelEntitlements\Exceptions\TransitionAlreadyResolved;

it('cancels a pending transition', function (): void {
    Event::fake([PlanTransitionCancelled::class]);

    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $transition = Entitlements::changePlan($anchor, $target, PlanTransitionMode::EndOfPeriod);

    Entitlements::cancelTransition($transition);

    expect($transition->fresh()->status)->toBe(PlanTransitionStatus::Cancelled);
    Event::assertDispatched(PlanTransitionCancelled::class);
});

it('rejects cancellation of a non-pending transition', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $transition = Entitlements::changePlan($anchor, $target, PlanTransitionMode::Immediate);

    Entitlements::cancelTransition($transition->fresh());
})->throws(TransitionAlreadyResolved::class);

use LucaLongo\LaravelEntitlements\Events\PlanTransitionFailed;

it('marks transition as failed when revalidation in apply phase fails', function (): void {
    Event::fake([PlanTransitionFailed::class]);

    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Pooled->value, 'quantity' => 100]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $endsAt = $anchor->ends_at;

    $target = makePlan([['type' => TestType::Pooled->value, 'quantity' => 3]]);
    $transition = Entitlements::changePlan($anchor, $target, PlanTransitionMode::EndOfPeriod);

    // Consume more than target capacity after scheduling
    Entitlements::consume($subscriber, TestType::Pooled, $subscriber, 10);

    $this->travelTo($endsAt->copy()->addSecond());

    Entitlements::applyDueTransitions();

    expect($transition->fresh()->status)->toBe(PlanTransitionStatus::Failed)
        ->and($transition->fresh()->failure_reason)->not->toBeNull();

    Event::assertDispatched(PlanTransitionFailed::class);
});

use LucaLongo\LaravelEntitlements\Entitlements as EntitlementsService;

/**
 * Simulates a concurrent scheduler holding a transition loaded while it was
 * still pending: applyTransition must re-check the persisted status itself.
 */
function applyTransitionDirectly(PlanTransition $transition): void
{
    $service = app(EntitlementsService::class);
    (new ReflectionMethod($service, 'applyTransition'))->invoke($service, $transition);
}

it('refuses to re-apply an already applied transition and keeps its outcome', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $endsAt = $anchor->ends_at;
    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $transition = Entitlements::changePlan($anchor, $target, PlanTransitionMode::EndOfPeriod);

    $stale = PlanTransition::query()->findOrFail($transition->getKey());

    $this->travelTo($endsAt->copy()->addSecond());
    expect(Entitlements::applyDueTransitions())->toBe(1);

    $appliedAt = $transition->fresh()->applied_at;
    $newAnchorId = $transition->fresh()->new_anchor_license_id;
    $licenseCount = License::query()->count();

    expect(fn () => applyTransitionDirectly($stale))->toThrow(TransitionAlreadyResolved::class);

    $fresh = $transition->fresh();
    expect($fresh->status)->toBe(PlanTransitionStatus::Applied)
        ->and($fresh->applied_at->equalTo($appliedAt))->toBeTrue()
        ->and($fresh->new_anchor_license_id)->toBe($newAnchorId)
        ->and(License::query()->count())->toBe($licenseCount);
});

it('refuses to apply a cancelled transition', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $endsAt = $anchor->ends_at;
    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $transition = Entitlements::changePlan($anchor, $target, PlanTransitionMode::EndOfPeriod);

    $stale = PlanTransition::query()->findOrFail($transition->getKey());

    Entitlements::cancelTransition($transition);

    $this->travelTo($endsAt->copy()->addSecond());
    $licenseCount = License::query()->count();

    expect(Entitlements::applyDueTransitions())->toBe(0);
    expect(fn () => applyTransitionDirectly($stale))->toThrow(TransitionAlreadyResolved::class);

    expect($transition->fresh()->status)->toBe(PlanTransitionStatus::Cancelled)
        ->and(License::query()->count())->toBe($licenseCount)
        ->and($anchor->fresh()->ends_at->equalTo($endsAt))->toBeTrue();
});

it('replaces a previous pending transition when scheduling a new one', function (): void {
    Event::fake([PlanTransitionCancelled::class, PlanTransitionScheduled::class]);

    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $endsAt = $anchor->ends_at;

    $targetA = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $targetB = makePlan([['type' => TestType::Single->value, 'quantity' => 2]]);

    $first = Entitlements::changePlan($anchor, $targetA, PlanTransitionMode::EndOfPeriod);
    $second = Entitlements::changePlan($anchor, $targetB, PlanTransitionMode::EndOfPeriod);

    expect($first->fresh()->status)->toBe(PlanTransitionStatus::Cancelled)
        ->and($second->fresh()->status)->toBe(PlanTransitionStatus::Pending)
        ->and($anchor->fresh()->pendingTransition()?->id)->toBe($second->id);

    Event::assertDispatched(PlanTransitionCancelled::class);

    $this->travelTo($endsAt->copy()->addSecond());

    expect(Entitlements::applyDueTransitions())->toBe(1);
    expect($first->fresh()->status)->toBe(PlanTransitionStatus::Cancelled)
        ->and($second->fresh()->status)->toBe(PlanTransitionStatus::Applied);

    $activeAnchors = License::query()
        ->whereNull('parent_id')
        ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->get();

    expect($activeAnchors)->toHaveCount(1)
        ->and($activeAnchors->first()->plan_id)->toBe($targetB->id);
});

it('an immediate change cancels the previous pending transition', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();

    $targetA = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $targetB = makePlan([['type' => TestType::Single->value, 'quantity' => 2]]);

    $pending = Entitlements::changePlan($anchor, $targetA, PlanTransitionMode::EndOfPeriod);
    $immediate = Entitlements::changePlan($anchor->fresh(), $targetB, PlanTransitionMode::Immediate);

    expect($pending->fresh()->status)->toBe(PlanTransitionStatus::Cancelled)
        ->and($immediate->status)->toBe(PlanTransitionStatus::Applied);
});

it('rejects non-positive quantity overrides on assignPlan', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Pooled->value, 'quantity' => 10, 'is_flexible' => true]]);

    expect(fn () => Entitlements::assignPlan($subscriber, $plan, now(), [$plan->items->first()->id => 0]))
        ->toThrow(InvalidArgumentException::class);
    expect(License::query()->count())->toBe(0);
});

it('rejects non-positive quantity overrides on changePlan', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Pooled->value, 'quantity' => 10, 'is_flexible' => true]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $target = makePlan([['type' => TestType::Pooled->value, 'quantity' => 20, 'is_flexible' => true]]);

    expect(fn () => Entitlements::changePlan($anchor, $target, PlanTransitionMode::Immediate, [$target->items->first()->id => -5]))
        ->toThrow(InvalidArgumentException::class);
});

it('stores a sanitized failure_reason for unexpected errors', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Pooled->value, 'quantity' => 10, 'is_flexible' => true]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $target = makePlan([['type' => TestType::Pooled->value, 'quantity' => 20, 'is_flexible' => true]]);

    // Crafted directly to bypass changePlan validation: revalidation at apply
    // time fails with a non-domain exception.
    $transition = PlanTransition::create([
        'anchor_license_id' => $anchor->id,
        'target_plan_id' => $target->id,
        'apply_mode' => PlanTransitionMode::EndOfPeriod->value,
        'status' => PlanTransitionStatus::Pending->value,
        'scheduled_at' => now()->subMinute(),
        'quantity_overrides' => [$target->items->first()->id => 0],
    ]);

    Entitlements::applyDueTransitions();

    $fresh = $transition->fresh();
    expect($fresh->status)->toBe(PlanTransitionStatus::Failed)
        ->and($fresh->failure_reason)->toBe(__('The plan change failed due to an unexpected error.'))
        ->and($fresh->failure_reason)->not->toContain('greater than zero');
});

it('cancelTransition refreshes the given instance', function (): void {
    $subscriber = Subscriber::create(['name' => 'acme']);
    $plan = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $anchor = Entitlements::assignPlan($subscriber, $plan, now())->first();
    $target = makePlan([['type' => TestType::Single->value, 'quantity' => 1]]);
    $transition = Entitlements::changePlan($anchor, $target, PlanTransitionMode::EndOfPeriod);

    Entitlements::cancelTransition($transition);

    expect($transition->status)->toBe(PlanTransitionStatus::Cancelled);
});
