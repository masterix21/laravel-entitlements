<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class NoOpPlanTransition extends RuntimeException
{
    public static function make(): self
    {
        return new self((string) __('The selected plan and quantities are identical to the current ones. Change at least one value.'));
    }
}
