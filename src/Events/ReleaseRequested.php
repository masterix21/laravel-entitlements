<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class ReleaseRequested
{
    use Dispatchable;

    public function __construct(
        public readonly LicenseUsage $usage,
    ) {}
}
