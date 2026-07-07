<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Support;

final class EntitlementTypeLabel
{
    public static function resolve(mixed $type): string
    {
        if ($type === null) {
            return '';
        }

        if (method_exists($type, 'getLabel')) {
            return $type->getLabel();
        }

        if (isset($type->name)) {
            return __($type->name);
        }

        return (string) $type;
    }
}
