<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Pages\Enums\SubNavigationPosition;
use Livewire\Livewire;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Filament\Clusters\SubscriptionPlansCluster;
use LucaLongo\LaravelEntitlements\Filament\Resources\PlanCategories\PlanCategoryResource;
use LucaLongo\LaravelEntitlements\Filament\Resources\Plans\Pages\ListPlans;
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

it('exposes isBooleanType and normalizeQuantity helpers used by the boolean toggle', function (): void {
    config()->set('entitlements.type_enum', AdvancedTestType::class);

    $isBooleanType = new ReflectionMethod(PlanForm::class, 'isBooleanType');
    $normalizeQuantity = new ReflectionMethod(PlanForm::class, 'normalizeQuantity');

    expect($isBooleanType->invoke(null, AdvancedTestType::Boolean->value))->toBeTrue()
        ->and($isBooleanType->invoke(null, AdvancedTestType::Computed->value))->toBeFalse()
        ->and($isBooleanType->invoke(null, AdvancedTestType::Slot->value))->toBeFalse()
        ->and($normalizeQuantity->invoke(null, 8, AdvancedTestType::Boolean->value))->toBe(1)
        ->and($normalizeQuantity->invoke(null, 0, AdvancedTestType::Boolean->value))->toBe(0)
        ->and($normalizeQuantity->invoke(null, 8, AdvancedTestType::Computed->value))->toBe(8);
});

it('renders boolean plan items as an enabled toggle instead of a quantity input', function (): void {
    config()->set('entitlements.type_enum', AdvancedTestType::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListPlans::class)
        ->mountAction('create')
        ->fillForm([
            'name' => 'Boolean plan',
            'billing_period' => BillingPeriod::Monthly->value,
            'items' => [
                'boolean-item' => [
                    'type' => AdvancedTestType::Boolean->value,
                    'quantity' => 1,
                    'is_flexible' => true,
                ],
                'computed-item' => [
                    'type' => AdvancedTestType::Computed->value,
                    'quantity' => 5,
                    'is_flexible' => true,
                ],
            ],
        ])
        ->assertFormFieldVisible('items.boolean-item.enabled')
        ->assertFormFieldHidden('items.boolean-item.quantity')
        ->assertFormFieldHidden('items.boolean-item.is_flexible')
        ->assertFormFieldHidden('items.computed-item.enabled')
        ->assertFormFieldVisible('items.computed-item.quantity')
        ->assertFormFieldVisible('items.computed-item.is_flexible')
        ->fillForm(['items.boolean-item.enabled' => false])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $plan = Plan::query()->where('name->en', 'Boolean plan')->firstOrFail();

    expect($plan->items()->where('type', AdvancedTestType::Boolean->value)->firstOrFail())
        ->quantity->toBe(0)
        ->is_flexible->toBeFalse()
        ->and($plan->items()->where('type', AdvancedTestType::Computed->value)->firstOrFail())
        ->quantity->toBe(5)
        ->is_flexible->toBeTrue();
});
