<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Facades\Entitlements;
use LucaLongo\LaravelEntitlements\Http\Resources\EntitlementSnapshotResource;
use LucaLongo\LaravelEntitlements\Http\Resources\LicenseResource;
use LucaLongo\LaravelEntitlements\Http\Resources\PlanResource;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanItem;
use Workbench\App\Enums\TestType;
use Workbench\App\Models\Subscriber;

beforeEach(function (): void {
    $this->subscriber = Subscriber::create(['name' => 'acme']);
    $this->request = Request::create('/');
});

it('serializes a plan with its items', function (): void {
    $plan = Plan::factory()->create([
        'name' => 'Pro',
        'billing_period' => BillingPeriod::Monthly->value,
        'is_recurring' => true,
        'is_active' => true,
    ]);
    PlanItem::factory()->for($plan)->create([
        'type' => TestType::Pooled->value,
        'quantity' => 100,
        'is_flexible' => true,
    ]);

    $plan->load('items');

    $payload = (new PlanResource($plan))->toArray($this->request);

    expect($payload['id'])->toBe($plan->id);
    expect($payload['name'])->toBe('Pro');
    expect($payload['billing_period'])->toBe(BillingPeriod::Monthly->value);
    expect($payload['is_recurring'])->toBeTrue();
    expect($payload['items'])->toHaveCount(1);
    expect($payload['items'][0]['id'])->toBe($plan->items->first()->getKey());
    expect($payload['items'][0]['type'])->toBe(TestType::Pooled->value);
    expect($payload['items'][0]['quantity'])->toBe(100);
    expect($payload['items'][0]['is_flexible'])->toBeTrue();
    expect($payload['items'][0])->toHaveKey('label');
});

it('serializes a license without leaking internal-only shape', function (): void {
    $plan = Plan::factory()->create([
        'billing_period' => BillingPeriod::Monthly->value,
        'is_recurring' => true,
    ]);
    PlanItem::factory()->for($plan)->create(['type' => TestType::Pooled->value, 'quantity' => 10]);

    $license = Entitlements::assignPlan($this->subscriber, $plan, now())->first();

    $payload = (new LicenseResource($license))->toArray($this->request);

    expect($payload)->toHaveKeys([
        'id', 'parent_id', 'type', 'label', 'slot_total', 'slot_used', 'remaining', 'starts_at', 'ends_at', 'is_valid',
    ]);
    expect($payload['type'])->toBe(TestType::Pooled->value);
    expect($payload['slot_total'])->toBe(10);
    expect($payload['remaining'])->toBe(10);
    expect($payload['is_valid'])->toBeTrue();
});

it('serializes an entitlement snapshot', function (): void {
    $plan = Plan::factory()->create([
        'billing_period' => BillingPeriod::Monthly->value,
        'is_recurring' => true,
    ]);
    PlanItem::factory()->for($plan)->create(['type' => TestType::Pooled->value, 'quantity' => 10]);

    Entitlements::assignPlan($this->subscriber, $plan, now());
    Entitlements::consume($this->subscriber, TestType::Pooled, $this->subscriber, 4);

    $snapshot = Entitlements::snapshot($this->subscriber);
    $payload = (new EntitlementSnapshotResource($snapshot))->toArray($this->request);

    $pooled = collect($payload['types'])->firstWhere('type', TestType::Pooled->value);

    expect($pooled)->not->toBeNull();
    expect($pooled['capacity'])->toBe(10);
    expect($pooled['used'])->toBe(4);
    expect($pooled['available'])->toBe(6);
    expect($pooled)->toHaveKey('label');
});
