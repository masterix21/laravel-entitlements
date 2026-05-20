<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LucaLongo\LaravelEntitlements\Models\PlanItem;
use Workbench\App\Enums\TestType;

final class PlanItemFactory extends Factory
{
    protected $model = PlanItem::class;

    public function definition(): array
    {
        return [
            'type' => TestType::Single->value,
            'quantity' => 1,
            'is_flexible' => false,
        ];
    }
}
