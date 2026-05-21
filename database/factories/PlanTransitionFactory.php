<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LucaLongo\LaravelEntitlements\Models\PlanTransition;

class PlanTransitionFactory extends Factory
{
    protected $model = PlanTransition::class;

    public function definition(): array
    {
        return [
            'apply_mode' => 'end_of_period',
            'status' => 'pending',
            'scheduled_at' => now()->addDay(),
            'quantity_overrides' => null,
        ];
    }
}
