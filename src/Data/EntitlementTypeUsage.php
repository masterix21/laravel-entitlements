<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Data;

use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;

final readonly class EntitlementTypeUsage
{
    public function __construct(
        public EntitlementType $type,
        public int $capacity,
        public int $used,
        public int $available,
    ) {}
}
