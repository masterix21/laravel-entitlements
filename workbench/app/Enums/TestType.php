<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Strategies\PoolStrategy;
use LucaLongo\LaravelEntitlements\Strategies\SlotStrategy;

enum TestType: string implements EntitlementType
{
    case Single = 'single';
    case Pooled = 'pooled';

    public function strategy(): EntitlementStrategy
    {
        return match ($this) {
            self::Single => new SlotStrategy(twoPhase: true),
            self::Pooled => new PoolStrategy(),
        };
    }
}
