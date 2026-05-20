<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Enums;

use Carbon\CarbonInterface;

enum BillingPeriod: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function advance(CarbonInterface $date): CarbonInterface
    {
        return match ($this) {
            self::Monthly => $date->copy()->addMonthNoOverflow(),
            self::Yearly => $date->copy()->addYear(),
        };
    }
}
