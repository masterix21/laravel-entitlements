<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;
use LucaLongo\LaravelEntitlements\Enums\PlanTransitionMode;
use LucaLongo\LaravelEntitlements\Enums\PlanTransitionStatus;

it('advances a date by one month for Monthly', function (): void {
    $start = now()->startOfDay();

    $advanced = BillingPeriod::Monthly->advance($start);

    expect($advanced->equalTo($start->copy()->addMonthNoOverflow()))->toBeTrue();
});

it('advances a date by one year for Yearly', function (): void {
    $start = now()->startOfDay();

    $advanced = BillingPeriod::Yearly->advance($start);

    expect($advanced->equalTo($start->copy()->addYear()))->toBeTrue();
});

it('lists license usage statuses', function (): void {
    expect(LicenseUsageStatus::cases())->toHaveCount(3);
    expect(LicenseUsageStatus::Active->value)->toBe('active');
    expect(LicenseUsageStatus::Releasing->value)->toBe('releasing');
    expect(LicenseUsageStatus::Released->value)->toBe('released');
});

it('exposes PlanTransitionMode cases', function (): void {
    expect(PlanTransitionMode::cases())
        ->toHaveCount(3)
        ->and(PlanTransitionMode::Immediate->value)->toBe('immediate')
        ->and(PlanTransitionMode::EndOfPeriod->value)->toBe('end_of_period')
        ->and(PlanTransitionMode::AtDate->value)->toBe('at_date');
});

it('exposes PlanTransitionStatus cases', function (): void {
    $values = array_map(fn ($c) => $c->value, PlanTransitionStatus::cases());
    expect($values)->toEqual(['pending', 'applied', 'failed', 'cancelled']);
});
