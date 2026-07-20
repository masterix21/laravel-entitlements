<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use LucaLongo\LaravelEntitlements\Data\EntitlementTypeUsage;
use LucaLongo\LaravelEntitlements\Events\LicenseReconciled;
use LucaLongo\LaravelEntitlements\Exceptions\ComputedUsageResolverException;
use LucaLongo\LaravelEntitlements\Exceptions\UnsupportedEntitlementOperationException;
use LucaLongo\LaravelEntitlements\Facades\Entitlements;
use LucaLongo\LaravelEntitlements\Filament\RelationManagers\LicensesRelationManager;
use LucaLongo\LaravelEntitlements\Http\Resources\EntitlementSnapshotResource;
use LucaLongo\LaravelEntitlements\Http\Resources\LicenseResource;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;
use LucaLongo\LaravelEntitlements\Models\Plan;
use Workbench\App\Enums\AdvancedTestType;
use Workbench\App\Models\Subject;
use Workbench\App\Models\Subscriber;

beforeEach(function (): void {
    config()->set('entitlements.type_enum', AdvancedTestType::class);

    $this->subscriber = Subscriber::create(['name' => 'acme']);
    $this->plan = Plan::factory()->create();
});

function advancedLicense(
    Subscriber $subscriber,
    Plan $plan,
    AdvancedTestType $type,
    int $total,
    int $used = 0,
    mixed $startsAt = null,
    mixed $endsAt = null,
): License {
    return License::create([
        'subscriber_type' => $subscriber->getMorphClass(),
        'subscriber_id' => $subscriber->getKey(),
        'plan_id' => $plan->getKey(),
        'type' => $type->value,
        'slot_total' => $total,
        'slot_used' => $used,
        'starts_at' => $startsAt ?? now()->subDay(),
        'ends_at' => $endsAt,
    ]);
}

it('uses one subscriber-wide computed value across all valid licenses', function (): void {
    advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Computed, 30, used: 11);
    advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Computed, 20, used: 9);
    advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Computed, 100, startsAt: now()->addDay());
    advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Computed, 100, endsAt: now()->subMinute());

    $calls = 0;
    Entitlements::resolveUsageUsing(AdvancedTestType::Computed, function (Subscriber $subscriber) use (&$calls): int {
        $calls++;

        expect($subscriber->is($this->subscriber))->toBeTrue();

        return 37;
    });

    expect(Entitlements::capacity($this->subscriber, AdvancedTestType::Computed))->toBe(50)
        ->and($calls)->toBe(0)
        ->and(Entitlements::available($this->subscriber, AdvancedTestType::Computed))->toBe(13)
        ->and($calls)->toBe(1)
        ->and(Entitlements::can($this->subscriber, AdvancedTestType::Computed, 13))->toBeTrue()
        ->and($calls)->toBe(2)
        ->and(Entitlements::can($this->subscriber, AdvancedTestType::Computed, 14))->toBeFalse()
        ->and($calls)->toBe(3);

    $snapshot = Entitlements::snapshot($this->subscriber);
    $computed = collect($snapshot->types)
        ->firstWhere(fn (EntitlementTypeUsage $usage): bool => $usage->type === AdvancedTestType::Computed);

    expect($calls)->toBe(4)
        ->and($computed->capacity)->toBe(50)
        ->and($computed->used)->toBe(37)
        ->and($computed->available)->toBe(13)
        ->and(LicenseUsage::query()->count())->toBe(0)
        ->and(License::query()->ofType(AdvancedTestType::Computed)->sum('slot_used'))->toBe(20);
});

it('clamps computed availability to zero when usage exceeds capacity', function (): void {
    advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Computed, 10);
    Entitlements::resolveUsageUsing(AdvancedTestType::Computed, fn (): int => 12);

    expect(Entitlements::available($this->subscriber, AdvancedTestType::Computed))->toBe(0)
        ->and(Entitlements::can($this->subscriber, AdvancedTestType::Computed))->toBeFalse();
});

it('rejects consumption for read-only entitlements without writing usage or counters', function (AdvancedTestType $type): void {
    $license = advancedLicense($this->subscriber, $this->plan, $type, 10);

    expect(fn () => Entitlements::consume(
        $this->subscriber,
        $type,
        Subject::create(),
    ))->toThrow(UnsupportedEntitlementOperationException::class, 'consume');

    expect($license->fresh()->slot_used)->toBe(0)
        ->and(LicenseUsage::query()->count())->toBe(0);
})->with([AdvancedTestType::Computed, AdvancedTestType::Boolean]);

it('rejects release operations for computed entitlements', function (string $operation): void {
    $license = advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Computed, 10);
    $subject = Subject::create();
    $usage = LicenseUsage::create([
        'license_id' => $license->getKey(),
        'subject_type' => $subject->getMorphClass(),
        'subject_id' => $subject->getKey(),
        'amount' => 1,
        'status' => 'active',
    ]);

    expect(fn () => Entitlements::$operation($usage))
        ->toThrow(UnsupportedEntitlementOperationException::class, $operation);

    expect($usage->fresh()->status->value)->toBe('active')
        ->and($license->fresh()->slot_used)->toBe(0);
})->with(['requestRelease', 'confirmRelease', 'forceRelease']);

it('fails diagnostically when a computed resolver is missing', function (): void {
    advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Computed, 10);

    expect(fn () => Entitlements::available($this->subscriber, AdvancedTestType::Computed))
        ->toThrow(ComputedUsageResolverException::class, 'Computed');
});

it('rejects resolver registration for non-computed entitlements', function (AdvancedTestType $type): void {
    expect(fn () => Entitlements::resolveUsageUsing($type, fn (): int => 0))
        ->toThrow(ComputedUsageResolverException::class, 'non-computed');
})->with([AdvancedTestType::Slot, AdvancedTestType::Boolean]);

it('rejects invalid computed resolver results', function (mixed $result): void {
    advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Computed, 10);
    Entitlements::resolveUsageUsing(AdvancedTestType::Computed, fn (): mixed => $result);

    expect(fn () => Entitlements::available($this->subscriber, AdvancedTestType::Computed))
        ->toThrow(ComputedUsageResolverException::class, 'non-negative integer');
})->with([
    'negative' => -1,
    'numeric string' => '1',
    'float' => 1.0,
    'null' => null,
]);

it('skips read-only licenses during reconciliation', function (): void {
    Event::fake([LicenseReconciled::class]);

    $computed = advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Computed, 10, used: 7);
    $boolean = advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Boolean, 1, used: 1);
    $slot = advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Slot, 10, used: 5);

    Entitlements::reconcile($computed);
    $result = Entitlements::recalculate($this->subscriber);

    expect($result)->toBe(['reconciled' => 1])
        ->and($computed->fresh()->slot_used)->toBe(7)
        ->and($computed->fresh()->last_checked_at)->toBeNull()
        ->and($boolean->fresh()->slot_used)->toBe(1)
        ->and($boolean->fresh()->last_checked_at)->toBeNull()
        ->and($slot->fresh()->slot_used)->toBe(0)
        ->and($slot->fresh()->last_checked_at)->not->toBeNull();

    Event::assertDispatchedTimes(LicenseReconciled::class, 1);
});

it('exposes boolean entitlements through allows without mutable usage semantics', function (): void {
    $active = advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Boolean, 1, used: 1);
    advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Boolean, 1, endsAt: now()->subMinute());
    advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Boolean, 1, startsAt: now()->addDay());

    expect(Entitlements::allows($this->subscriber, AdvancedTestType::Boolean))->toBeTrue()
        ->and(Entitlements::capacity($this->subscriber, AdvancedTestType::Boolean))->toBe(1)
        ->and(Entitlements::available($this->subscriber, AdvancedTestType::Boolean))->toBe(1)
        ->and($active->fresh()->slot_used)->toBe(1)
        ->and(LicenseUsage::query()->count())->toBe(0);

    expect(fn () => Entitlements::can($this->subscriber, AdvancedTestType::Boolean))
        ->toThrow(UnsupportedEntitlementOperationException::class, 'use "allows"');

    expect(fn () => Entitlements::allows($this->subscriber, AdvancedTestType::Slot))
        ->toThrow(UnsupportedEntitlementOperationException::class, 'only supported for boolean');
});

it('returns false for a boolean entitlement without valid capacity', function (): void {
    advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Boolean, 1, endsAt: now()->subMinute());

    expect(Entitlements::allows($this->subscriber, AdvancedTestType::Boolean))->toBeFalse();
});

it('serializes computed snapshots and licenses and omits misleading Filament usage counters', function (): void {
    $license = advancedLicense($this->subscriber, $this->plan, AdvancedTestType::Computed, 10, used: 8);
    Entitlements::resolveUsageUsing(AdvancedTestType::Computed, fn (): int => 4);

    $request = Request::create('/');
    $snapshotPayload = (new EntitlementSnapshotResource(Entitlements::snapshot($this->subscriber)))->toArray($request);
    $licensePayload = (new LicenseResource($license))->toArray($request);
    $resourceUsageLine = new ReflectionMethod(LicensesRelationManager::class, 'resourceUsageLine');

    $computed = collect($snapshotPayload['types'])->firstWhere('type', AdvancedTestType::Computed->value);

    expect($computed)->toMatchArray([
        'capacity' => 10,
        'used' => 4,
        'available' => 6,
    ])->and($licensePayload['slot_used'])->toBe(8)
        ->and($resourceUsageLine->invoke(null, $license))->toBe('10 Computed');
});
