<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class IncompatiblePlanTransition extends RuntimeException
{
    public static function forType(string $type): self
    {
        return new self((string) __('The selected plan does not include the “:type” feature you are currently using.', [
            'type' => self::humanizeType($type),
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
