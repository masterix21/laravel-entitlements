<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class TransitionAlreadyResolved extends RuntimeException
{
    public static function forStatus(string $status): self
    {
        return new self((string) __('This plan change has already been processed and cannot be modified.'));
    }
}
