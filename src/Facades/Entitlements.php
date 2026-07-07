<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Facades;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Data\EntitlementSnapshot;
use LucaLongo\LaravelEntitlements\Entitlements as EntitlementsService;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;
use LucaLongo\LaravelEntitlements\Models\Plan;

/**
 * @method static Collection<int, License> assignPlan(Model $subscriber, Plan $plan, CarbonInterface $startsAt, array $quantityOverrides = [])
 * @method static LicenseUsage consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1)
 * @method static void requestRelease(LicenseUsage $usage)
 * @method static void confirmRelease(LicenseUsage $usage)
 * @method static void forceRelease(LicenseUsage $usage)
 * @method static int available(Model $subscriber, EntitlementType $type)
 * @method static int capacity(Model $subscriber, EntitlementType $type)
 * @method static bool can(Model $subscriber, EntitlementType $type, int $amount = 1)
 * @method static EntitlementSnapshot snapshot(Model $subscriber)
 * @method static void reconcile(License $license)
 * @method static array recalculate(Model $subscriber)
 */
final class Entitlements extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EntitlementsService::class;
    }
}
