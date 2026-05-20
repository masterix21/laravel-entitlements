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
