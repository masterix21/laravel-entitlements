<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;
use LucaLongo\LaravelEntitlements\Models\Plan;
use Workbench\App\Enums\TestType;
use Workbench\App\Models\Subject;
use Workbench\App\Models\Subscriber;

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
