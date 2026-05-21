<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class TransitionAlreadyResolved extends RuntimeException
{
    public static function forStatus(string $status): self
    {
        return new self("Plan transition is already resolved with status [{$status}].");
    }
}
