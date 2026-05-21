<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class AnchorNotActiveForTransition extends RuntimeException
{
    public static function notAnchor(int $licenseId): self
    {
        return new self("License [{$licenseId}] is not an anchor license.");
    }

    public static function expired(int $licenseId): self
    {
        return new self("Anchor license [{$licenseId}] is no longer active.");
    }
}
