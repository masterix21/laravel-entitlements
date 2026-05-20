<?php

declare(strict_types=1);

return [
    'type_enum' => null,

    'models' => [
        'plan_category' => \LucaLongo\LaravelEntitlements\Models\PlanCategory::class,
        'plan' => \LucaLongo\LaravelEntitlements\Models\Plan::class,
        'plan_item' => \LucaLongo\LaravelEntitlements\Models\PlanItem::class,
        'license' => \LucaLongo\LaravelEntitlements\Models\License::class,
        'license_usage' => \LucaLongo\LaravelEntitlements\Models\LicenseUsage::class,
    ],

    'table_names' => [
        'plan_categories' => 'entitlement_plan_categories',
        'plans' => 'entitlement_plans',
        'plan_items' => 'entitlement_plan_items',
        'licenses' => 'entitlement_licenses',
        'license_usages' => 'entitlement_license_usages',
    ],
];
