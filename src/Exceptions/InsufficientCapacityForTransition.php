<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class InsufficientCapacityForTransition extends RuntimeException
{
    public static function forType(string $type, int $used, int $available): self
    {
        return new self("Target plan capacity for type [{$type}] ({$available}) is below current usage ({$used}).");
    }
}
