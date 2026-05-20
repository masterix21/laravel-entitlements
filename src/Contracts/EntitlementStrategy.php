<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Contracts;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

interface EntitlementStrategy
{
    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage;

    public function requestRelease(LicenseUsage $usage): void;

    public function confirmRelease(LicenseUsage $usage): void;

    public function forceRelease(LicenseUsage $usage): void;

    public function supportsTwoPhaseRelease(): bool;
}
