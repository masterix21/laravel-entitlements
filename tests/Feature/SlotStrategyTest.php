<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;
use LucaLongo\LaravelEntitlements\Events\LicenseConsumed;
use LucaLongo\LaravelEntitlements\Events\LicenseReleased;
use LucaLongo\LaravelEntitlements\Events\ReleaseRequested;
use LucaLongo\LaravelEntitlements\Exceptions\NoEntitlementAvailableException;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;
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
    $strategy = new SlotStrategy;

    $usage = $strategy->consume($this->subscriber, TestType::Single, $subject);

    expect($usage->amount)->toBe(1);
    expect($usage->status)->toBe(LicenseUsageStatus::Active);
    expect($this->license->fresh()->slot_used)->toBe(1);
    Event::assertDispatched(LicenseConsumed::class);
});

it('consume throws when no slot available', function (): void {
    $this->license->update(['slot_used' => 2]);
    $strategy = new SlotStrategy;
    $subject = Subject::create();

    expect(fn () => $strategy->consume($this->subscriber, TestType::Single, $subject))
        ->toThrow(NoEntitlementAvailableException::class);
});

it('consume with amount creates one usage per slot', function (): void {
    Event::fake([LicenseConsumed::class]);

    $subject = Subject::create();
    $strategy = new SlotStrategy;

    $usage = $strategy->consume($this->subscriber, TestType::Single, $subject, 2);

    $usages = $this->license->usages()->get();
    expect($usages)->toHaveCount(2);
    expect($usages->pluck('amount')->all())->toBe([1, 1]);
    expect($usage->getKey())->toBe($usages->first()->getKey());
    expect($this->license->fresh()->slot_used)->toBe(2);
    Event::assertDispatchedTimes(LicenseConsumed::class, 2);
});

it('consume with amount spreads across licenses expiring first', function (): void {
    $later = License::create([
        'subscriber_type' => $this->subscriber->getMorphClass(),
        'subscriber_id' => $this->subscriber->id,
        'plan_id' => $this->plan->id,
        'type' => TestType::Single->value,
        'slot_total' => 2,
        'slot_used' => 0,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addYear(),
    ]);

    $strategy = new SlotStrategy;

    $strategy->consume($this->subscriber, TestType::Single, Subject::create(), 3);

    expect($this->license->fresh()->slot_used)->toBe(2);
    expect($later->fresh()->slot_used)->toBe(1);
});

it('consume with amount beyond total capacity throws and consumes nothing', function (): void {
    $strategy = new SlotStrategy;
    $subject = Subject::create();

    expect(fn () => $strategy->consume($this->subscriber, TestType::Single, $subject, 3))
        ->toThrow(NoEntitlementAvailableException::class);

    expect($this->license->fresh()->slot_used)->toBe(0);
    expect(LicenseUsage::query()->count())->toBe(0);
});

it('no-availability exception message is user-safe and carries context', function (): void {
    $this->license->update(['slot_used' => 2]);
    $strategy = new SlotStrategy;
    $subject = Subject::create();

    try {
        $strategy->consume($this->subscriber, TestType::Single, $subject);
        $this->fail('Expected NoEntitlementAvailableException.');
    } catch (NoEntitlementAvailableException $e) {
        expect($e->getMessage())->not->toContain('Workbench')
            ->and($e->subscriber?->is($this->subscriber))->toBeTrue()
            ->and($e->type)->toBe(TestType::Single)
            ->and($e->requested)->toBe(1);
    }
});

it('consume rejects a non-positive amount', function (): void {
    $strategy = new SlotStrategy;
    $subject = Subject::create();

    expect(fn () => $strategy->consume($this->subscriber, TestType::Single, $subject, 0))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => $strategy->consume($this->subscriber, TestType::Single, $subject, -1))
        ->toThrow(InvalidArgumentException::class);
});

it('releasing one of the usages frees a single slot', function (): void {
    $strategy = new SlotStrategy;
    $usage = $strategy->consume($this->subscriber, TestType::Single, Subject::create(), 2);

    $strategy->forceRelease($usage);

    expect($this->license->fresh()->slot_used)->toBe(1);
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
    $strategy = new SlotStrategy;
    $usage = $strategy->consume($this->subscriber, TestType::Single, Subject::create());
    $strategy->forceRelease($usage);
    $beforeUsed = $this->license->fresh()->slot_used;

    $strategy->forceRelease($usage);

    expect($this->license->fresh()->slot_used)->toBe($beforeUsed);
});

it('forceRelease with a stale instance does not double decrement', function (): void {
    $strategy = new SlotStrategy;
    $first = $strategy->consume($this->subscriber, TestType::Single, Subject::create());
    $strategy->consume($this->subscriber, TestType::Single, Subject::create());

    $stale = LicenseUsage::query()->findOrFail($first->getKey());

    $strategy->forceRelease($first);
    $strategy->forceRelease($stale);

    expect($this->license->fresh()->slot_used)->toBe(1);
});
