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
            'type' => $this->resolveType(),
            'quantity' => 1,
            'is_flexible' => false,
        ];
    }

    private function resolveType(): string
    {
        /** @var class-string<\BackedEnum>|null $enum */
        $enum = config('entitlements.type_enum');

        if ($enum === null && class_exists(TestType::class)) {
            $enum = TestType::class;
        }

        return $enum::cases()[0]->value;
    }
}
