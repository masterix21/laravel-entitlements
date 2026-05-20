<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;

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
