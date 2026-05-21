<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements;

use LucaLongo\LaravelEntitlements\Commands\ApplyDuePlanTransitionsCommand;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Exceptions\InvalidEntitlementTypeException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class LaravelEntitlementsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-entitlements')
            ->hasConfigFile('entitlements')
            ->hasMigrations([
                'create_entitlement_plan_categories_table',
                'create_entitlement_plans_table',
                'create_entitlement_plan_items_table',
                'create_entitlement_licenses_table',
                'create_entitlement_license_usages_table',
                'add_allows_multiple_active_plans_to_entitlement_plan_categories_table',
                'create_entitlement_plan_transitions_table',
            ])
            ->hasCommand(ApplyDuePlanTransitionsCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Entitlements::class);
    }

    public function packageBooted(): void
    {
        $this->validateTypeEnum();
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath(),
        ], 'laravel-entitlements-translations');
    }

    private function validateTypeEnum(): void
    {
        $class = config('entitlements.type_enum');

        if ($class === null) {
            return;
        }

        if (! is_string($class) || ! enum_exists($class) || ! is_subclass_of($class, EntitlementType::class)) {
            throw InvalidEntitlementTypeException::invalid((string) $class);
        }
    }
}
