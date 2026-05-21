<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class InsufficientCapacityForTransition extends RuntimeException
{
    public static function forType(string $type, int $used, int $available): self
    {
        return new self((string) __('The selected plan offers :available “:type” but :used are already in use.', [
            'type' => self::humanizeType($type),
            'used' => $used,
            'available' => $available,
        ]));
    }

    private static function humanizeType(string $type): string
    {
        $translated = __($type);

        if ($translated !== $type) {
            return $translated;
        }

        return ucwords(str_replace(['_', '-'], ' ', $type));
    }
}
