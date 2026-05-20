<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Strategies;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class PoolStrategy implements EntitlementStrategy
{
    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage
    {
        throw new \LogicException('PoolStrategy::consume not yet implemented');
    }

    public function requestRelease(LicenseUsage $usage): void
    {
        $this->forceRelease($usage);
    }

    public function confirmRelease(LicenseUsage $usage): void
    {
        $this->forceRelease($usage);
    }

    public function forceRelease(LicenseUsage $usage): void
    {
        throw new \LogicException('PoolStrategy::forceRelease not yet implemented');
    }

    public function supportsTwoPhaseRelease(): bool
    {
        return false;
    }
}
