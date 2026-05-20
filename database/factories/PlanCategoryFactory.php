<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LucaLongo\LaravelEntitlements\Models\PlanCategory;

final class PlanCategoryFactory extends Factory
{
    protected $model = PlanCategory::class;

    public function definition(): array
    {
        return [
            'name' => ['en' => $this->faker->words(2, true)],
            'sort' => 0,
        ];
    }
}
