<?php

declare(strict_types=1);

use Filament\Pages\Enums\SubNavigationPosition;
use LucaLongo\LaravelEntitlements\Filament\Clusters\SubscriptionPlansCluster;
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

it('groups both resources under the Subscription Plans cluster with top navigation', function (): void {
    expect(PlanResource::getCluster())->toBe(SubscriptionPlansCluster::class)
        ->and(PlanCategoryResource::getCluster())->toBe(SubscriptionPlansCluster::class)
        ->and(SubscriptionPlansCluster::getNavigationLabel())->toBe(__('Subscription Plans'))
        ->and(SubscriptionPlansCluster::getSubNavigationPosition())->toBe(SubNavigationPosition::Top);
});

it('exposes tab labels for the cluster sub-navigation', function (): void {
    expect(PlanResource::getNavigationLabel())->toBe(__('Plans'))
        ->and(PlanCategoryResource::getNavigationLabel())->toBe(__('Plan Categories'));
});

it('exposes translated model labels', function (): void {
    expect(PlanResource::getModelLabel())->toBe(__('Plan'));
    expect(PlanResource::getPluralModelLabel())->toBe(__('Plans'));
    expect(PlanCategoryResource::getModelLabel())->toBe(__('Plan Category'));
    expect(PlanCategoryResource::getPluralModelLabel())->toBe(__('Plan Categories'));
});
