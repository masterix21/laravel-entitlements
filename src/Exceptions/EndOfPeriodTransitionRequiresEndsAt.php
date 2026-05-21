<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class EndOfPeriodTransitionRequiresEndsAt extends RuntimeException
{
    public static function make(): self
    {
        return new self((string) __('A plan without an end date cannot be changed at the end of the period. Use the immediate option instead.'));
    }
}
