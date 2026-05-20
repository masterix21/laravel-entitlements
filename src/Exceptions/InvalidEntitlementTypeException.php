<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use RuntimeException;

final class InvalidEntitlementTypeException extends RuntimeException
{
    public static function missing(): self
    {
        return new self('config("entitlements.type_enum") is not set.');
    }

    public static function invalid(string $class): self
    {
        return new self(sprintf(
            'Class [%s] must implement %s and be a backed enum.',
            $class,
            EntitlementType::class,
        ));
    }
}
