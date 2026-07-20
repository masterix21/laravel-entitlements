<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Strategies\BooleanStrategy;
use LucaLongo\LaravelEntitlements\Strategies\ComputedStrategy;
use LucaLongo\LaravelEntitlements\Strategies\SlotStrategy;

enum AdvancedTestType: string implements EntitlementType
{
    case Computed = 'computed';
    case Boolean = 'boolean';
    case Slot = 'slot';

    public function strategy(): EntitlementStrategy
    {
        return match ($this) {
            self::Computed => new ComputedStrategy,
            self::Boolean => new BooleanStrategy,
            self::Slot => new SlotStrategy,
        };
    }
}
