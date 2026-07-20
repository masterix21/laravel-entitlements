<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Support\EntitlementTypeLabel;
use RuntimeException;

final class ComputedUsageResolverException extends RuntimeException
{
    public static function missing(EntitlementType $type): self
    {
        return new self((string) __('No computed usage resolver is registered for the ":type" entitlement.', [
            'type' => EntitlementTypeLabel::resolve($type),
        ]));
    }

    public static function notComputed(EntitlementType $type): self
    {
        return new self((string) __('A computed usage resolver cannot be registered for the non-computed ":type" entitlement.', [
            'type' => EntitlementTypeLabel::resolve($type),
        ]));
    }

    public static function invalidResult(EntitlementType $type): self
    {
        return new self((string) __('The computed usage resolver for the ":type" entitlement must return a non-negative integer.', [
            'type' => EntitlementTypeLabel::resolve($type),
        ]));
    }
}
