<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanItem;

enum ConsumerEntitlementType: string
{
    case Seats = 'seats';
    case Storage = 'storage';
}

it('builds a plan item from the consumer-configured type enum without the workbench enum', function (): void {
    config()->set('entitlements.type_enum', ConsumerEntitlementType::class);

    $plan = Plan::factory()->create();

    $item = PlanItem::factory()->for($plan)->create();

    expect($item->type)->toBe(ConsumerEntitlementType::Seats);
    expect($item->type->value)->toBe(ConsumerEntitlementType::cases()[0]->value);
});
