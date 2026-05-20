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
        'slot_used' => 0,
        'starts_at' => now()->subDay(),
    ]);

    Entitlements::consume($this->subscriber, TestType::Pooled, Subject::create(), amount: 10);

    $license->update(['slot_used' => 99]);

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
