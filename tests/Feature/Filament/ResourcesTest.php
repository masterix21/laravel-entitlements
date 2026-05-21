<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Filament\Resources\PlanCategories\PlanCategoryResource;
use LucaLongo\LaravelEntitlements\Filament\Resources\Plans\PlanResource;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanCategory;

it('PlanResource targets the package Plan model', function (): void {
    expect(PlanResource::getModel())->toBe(Plan::class);
});

it('PlanCategoryResource targets the package PlanCategory model', function (): void {
    expect(PlanCategoryResource::getModel())->toBe(PlanCategory::class);
});

it('PlanCategoryResource nests under the Subscription Plans navigation item', function (): void {
    expect(PlanCategoryResource::getNavigationParentItem())->toBe(__('Subscription Plans'));
    expect(PlanResource::getNavigationLabel())->toBe(__('Subscription Plans'));
});

it('exposes translated model labels', function (): void {
    expect(PlanResource::getModelLabel())->toBe(__('Plan'));
    expect(PlanResource::getPluralModelLabel())->toBe(__('Plans'));
    expect(PlanCategoryResource::getModelLabel())->toBe(__('Plan Category'));
    expect(PlanCategoryResource::getPluralModelLabel())->toBe(__('Plan Categories'));
});
