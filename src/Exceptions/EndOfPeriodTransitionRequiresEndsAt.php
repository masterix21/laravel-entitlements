<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class EndOfPeriodTransitionRequiresEndsAt extends RuntimeException
{
    public static function make(): self
    {
        return new self('End-of-period transitions require an anchor with a non-null ends_at.');
    }
}
