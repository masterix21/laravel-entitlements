<?php

declare(strict_types=1);
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanCategory;
use LucaLongo\LaravelEntitlements\Models\PlanItem;
use LucaLongo\LaravelEntitlements\Models\PlanTransition;

return [
    'type_enum' => null,

    'models' => [
        'plan_category' => PlanCategory::class,
        'plan' => Plan::class,
        'plan_item' => PlanItem::class,
        'license' => License::class,
        'license_usage' => LicenseUsage::class,
        'plan_transition' => PlanTransition::class,
    ],

    'table_names' => [
        'plan_categories' => 'entitlement_plan_categories',
        'plans' => 'entitlement_plans',
        'plan_items' => 'entitlement_plan_items',
        'licenses' => 'entitlement_licenses',
        'license_usages' => 'entitlement_license_usages',
        'plan_transitions' => 'entitlement_plan_transitions',
    ],
];
