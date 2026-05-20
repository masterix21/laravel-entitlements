<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Strategies;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class SlotStrategy implements EntitlementStrategy
{
    public function __construct(public readonly bool $twoPhase = false) {}

    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage
    {
        throw new \LogicException('SlotStrategy::consume not yet implemented');
    }

    public function requestRelease(LicenseUsage $usage): void
    {
        throw new \LogicException('SlotStrategy::requestRelease not yet implemented');
    }

    public function confirmRelease(LicenseUsage $usage): void
    {
        throw new \LogicException('SlotStrategy::confirmRelease not yet implemented');
    }

    public function forceRelease(LicenseUsage $usage): void
    {
        throw new \LogicException('SlotStrategy::forceRelease not yet implemented');
    }

    public function supportsTwoPhaseRelease(): bool
    {
        return $this->twoPhase;
    }
}
