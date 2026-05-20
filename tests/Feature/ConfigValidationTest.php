<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Exceptions\InvalidEntitlementTypeException;
use LucaLongo\LaravelEntitlements\LaravelEntitlementsServiceProvider;
use Orchestra\Testbench\Factories\UserFactory;
use Workbench\App\Enums\TestType;

it('throws when type_enum is set to a non-enum class', function (): void {
    config()->set('entitlements.type_enum', UserFactory::class);

    $provider = new LaravelEntitlementsServiceProvider($this->app);

    expect(fn () => $provider->packageBooted())
        ->toThrow(InvalidEntitlementTypeException::class);
});

it('throws when type_enum is set to an enum that does not implement EntitlementType', function (): void {
    config()->set('entitlements.type_enum', BillingPeriod::class);

    $provider = new LaravelEntitlementsServiceProvider($this->app);

    expect(fn () => $provider->packageBooted())
        ->toThrow(InvalidEntitlementTypeException::class);
});

it('passes validation for a valid EntitlementType enum', function (): void {
    config()->set('entitlements.type_enum', TestType::class);

    $provider = new LaravelEntitlementsServiceProvider($this->app);

    $provider->packageBooted();

    expect(true)->toBeTrue();
});
