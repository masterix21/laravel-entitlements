<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class AnchorNotActiveForTransition extends RuntimeException
{
    public static function notAnchor(int $licenseId): self
    {
        return new self((string) __('This license is part of a plan group and cannot be changed directly.'));
    }

    public static function expired(int $licenseId): self
    {
        return new self((string) __('This plan is no longer active and cannot be changed.'));
    }
}
