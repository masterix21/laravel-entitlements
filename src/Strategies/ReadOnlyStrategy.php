<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Strategies;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Exceptions\UnsupportedEntitlementOperationException;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

abstract class ReadOnlyStrategy implements EntitlementStrategy
{
    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage
    {
        throw UnsupportedEntitlementOperationException::forReadOnlyType($type, 'consume');
    }

    public function requestRelease(LicenseUsage $usage): void
    {
        throw UnsupportedEntitlementOperationException::forReadOnlyType($usage->license->type, 'requestRelease');
    }

    public function confirmRelease(LicenseUsage $usage): void
    {
        throw UnsupportedEntitlementOperationException::forReadOnlyType($usage->license->type, 'confirmRelease');
    }

    public function forceRelease(LicenseUsage $usage): void
    {
        throw UnsupportedEntitlementOperationException::forReadOnlyType($usage->license->type, 'forceRelease');
    }

    public function supportsTwoPhaseRelease(): bool
    {
        return false;
    }
}
