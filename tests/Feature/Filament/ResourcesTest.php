<?php

declare(strict_types=1);

use Filament\Pages\Enums\SubNavigationPosition;
use LucaLongo\LaravelEntitlements\Filament\Clusters\SubscriptionPlansCluster;
use LucaLongo\LaravelEntitlements\Filament\Resources\PlanCategories\PlanCategoryResource;
use LucaLongo\LaravelEntitlements\Filament\Resources\Plans\PlanResource;
use LucaLongo\LaravelEntitlements\Filament\Resources\Plans\Schemas\PlanForm;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanCategory;
use Workbench\App\Enums\AdvancedTestType;

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

it('renders boolean plan items as an enabled toggle instead of a quantity input', function (): void {
    config()->set('entitlements.type_enum', AdvancedTestType::class);

    $isBooleanType = new ReflectionMethod(PlanForm::class, 'isBooleanType');
    $normalizeQuantity = new ReflectionMethod(PlanForm::class, 'normalizeQuantity');
    $source = (string) file_get_contents((string) (new ReflectionClass(PlanForm::class))->getFileName());

    expect($isBooleanType->invoke(null, AdvancedTestType::Boolean->value))->toBeTrue()
        ->and($isBooleanType->invoke(null, AdvancedTestType::Computed->value))->toBeFalse()
        ->and($isBooleanType->invoke(null, AdvancedTestType::Slot->value))->toBeFalse()
        ->and($normalizeQuantity->invoke(null, 8, AdvancedTestType::Boolean->value))->toBe(1)
        ->and($normalizeQuantity->invoke(null, 0, AdvancedTestType::Boolean->value))->toBe(0)
        ->and($normalizeQuantity->invoke(null, 8, AdvancedTestType::Computed->value))->toBe(8)
        ->and($source)->toContain('Group::make()')
        ->and($source)->toContain("Toggle::make('enabled')")
        ->and($source)->toContain("\$set('quantity', \$state ? 1 : 0)")
        ->and($source)->toContain("\$set('is_flexible', false)")
        ->and($source)->toContain('->dehydrated(false)');
});
