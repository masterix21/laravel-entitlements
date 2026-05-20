<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Enums;

enum LicenseUsageStatus: string
{
    case Active = 'active';
    case Releasing = 'releasing';
    case Released = 'released';
}
