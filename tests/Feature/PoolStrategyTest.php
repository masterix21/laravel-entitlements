<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use LucaLongo\LaravelEntitlements\Exceptions\NoEntitlementAvailableException;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Strategies\PoolStrategy;
use Workbench\App\Enums\TestType;
use Workbench\App\Models\Subject;
use Workbench\App\Models\Subscriber;

beforeEach(function (): void {
    $this->subscriber = Subscriber::create(['name' => 'acme']);
    $this->plan = Plan::factory()->create();
});

function makePoolLicense(Subscriber $subscriber, Plan $plan, int $total, ?CarbonInterface $endsAt): License
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
    $strategy = new PoolStrategy;

    $usage = $strategy->consume($this->subscriber, TestType::Pooled, $subject, amount: 30);

    expect($usage->amount)->toBe(30);
    expect($license->fresh()->slot_used)->toBe(30);
});

it('drains across multiple licenses ordered by ends_at ascending then nulls last', function (): void {
    $early = makePoolLicense($this->subscriber, $this->plan, 20, now()->addDay());
    $late = makePoolLicense($this->subscriber, $this->plan, 50, now()->addMonth());
    $perpetual = makePoolLicense($this->subscriber, $this->plan, 100, null);

    $strategy = new PoolStrategy;
    $strategy->consume($this->subscriber, TestType::Pooled, Subject::create(), amount: 60);

    expect($early->fresh()->slot_used)->toBe(20);
    expect($late->fresh()->slot_used)->toBe(40);
    expect($perpetual->fresh()->slot_used)->toBe(0);
});

it('throws when pool capacity is insufficient', function (): void {
    makePoolLicense($this->subscriber, $this->plan, 10, now()->addMonth());
    $strategy = new PoolStrategy;

    expect(fn () => $strategy->consume($this->subscriber, TestType::Pooled, Subject::create(), amount: 50))
        ->toThrow(NoEntitlementAvailableException::class);
});

it('forceRelease subtracts amount from license slot_used', function (): void {
    $license = makePoolLicense($this->subscriber, $this->plan, 100, now()->addMonth());
    $strategy = new PoolStrategy;
    $usage = $strategy->consume($this->subscriber, TestType::Pooled, Subject::create(), amount: 40);

    $strategy->forceRelease($usage);

    expect($license->fresh()->slot_used)->toBe(0);
});

it('supportsTwoPhaseRelease is false', function (): void {
    expect((new PoolStrategy)->supportsTwoPhaseRelease())->toBeFalse();
});

it('forceRelease with a stale instance does not double decrement', function (): void {
    $license = makePoolLicense($this->subscriber, $this->plan, 100, now()->addMonth());
    $strategy = new PoolStrategy;
    $usage = $strategy->consume($this->subscriber, TestType::Pooled, Subject::create(), amount: 30);

    $stale = LicenseUsage::query()->findOrFail($usage->getKey());

    $strategy->forceRelease($usage);
    $strategy->forceRelease($stale);

    expect($license->fresh()->slot_used)->toBe(0);
});
