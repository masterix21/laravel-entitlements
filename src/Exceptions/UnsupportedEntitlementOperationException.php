<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Support\EntitlementTypeLabel;
use RuntimeException;

final class UnsupportedEntitlementOperationException extends RuntimeException
{
    public static function forReadOnlyType(EntitlementType $type, string $operation): self
    {
        return new self((string) __('The ":operation" operation is not supported for the read-only ":type" entitlement.', [
            'operation' => $operation,
            'type' => EntitlementTypeLabel::resolve($type),
        ]));
    }

    public static function allowsRequiresBoolean(EntitlementType $type): self
    {
        return new self((string) __('The "allows" operation is only supported for boolean entitlements; ":type" uses a different strategy.', [
            'type' => EntitlementTypeLabel::resolve($type),
        ]));
    }

    public static function canRequiresQuantified(EntitlementType $type): self
    {
        return new self((string) __('The "can" operation is not supported for boolean entitlements; use "allows" for ":type".', [
            'type' => EntitlementTypeLabel::resolve($type),
        ]));
    }
}
