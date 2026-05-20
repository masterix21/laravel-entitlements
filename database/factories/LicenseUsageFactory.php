<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class LicenseUsageFactory extends Factory
{
    protected $model = LicenseUsage::class;

    public function definition(): array
    {
        return [
            'amount' => 1,
            'status' => LicenseUsageStatus::Active,
        ];
    }
}
