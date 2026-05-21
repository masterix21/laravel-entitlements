<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class InvalidTransitionScheduledDate extends RuntimeException
{
    public static function missing(): self
    {
        return new self((string) __('A scheduled date is required for this plan change.'));
    }

    public static function notInFuture(): self
    {
        return new self((string) __('The scheduled date must be in the future.'));
    }
}
