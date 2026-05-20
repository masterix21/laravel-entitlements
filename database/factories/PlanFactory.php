<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Models\Plan;

final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'plan_category_id' => null,
            'name' => ['en' => $this->faker->words(2, true)],
            'billing_period' => BillingPeriod::Monthly->value,
            'is_recurring' => false,
            'is_active' => true,
        ];
    }
}
