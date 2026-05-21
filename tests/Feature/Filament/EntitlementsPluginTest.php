<?php

declare(strict_types=1);

use Filament\Panel;
use LucaLongo\LaravelEntitlements\Filament\EntitlementsPlugin;
use LucaLongo\LaravelEntitlements\Filament\Resources\PlanCategories\PlanCategoryResource;
use LucaLongo\LaravelEntitlements\Filament\Resources\Plans\PlanResource;

it('has a stable identifier', function (): void {
    expect(EntitlementsPlugin::make()->getId())->toBe('laravel-entitlements');
});

it('registers both resources by default', function (): void {
    $panel = filament()->getPanel('admin');

    expect($panel->getResources())->toContain(PlanResource::class);
    expect($panel->getResources())->toContain(PlanCategoryResource::class);
});

it('can opt out of the plan resource', function (): void {
    $panel = (new Panel)->id('test-out-plan');
    EntitlementsPlugin::make()->withoutPlanResource()->register($panel);

    expect($panel->getResources())->not->toContain(PlanResource::class);
    expect($panel->getResources())->toContain(PlanCategoryResource::class);
});

it('can opt out of the plan category resource', function (): void {
    $panel = (new Panel)->id('test-out-category');
    EntitlementsPlugin::make()->withoutPlanCategoryResource()->register($panel);

    expect($panel->getResources())->toContain(PlanResource::class);
    expect($panel->getResources())->not->toContain(PlanCategoryResource::class);
});
