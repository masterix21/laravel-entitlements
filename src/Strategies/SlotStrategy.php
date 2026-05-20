<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;
use LucaLongo\LaravelEntitlements\Events\LicenseConsumed;
use LucaLongo\LaravelEntitlements\Events\LicenseReleased;
use LucaLongo\LaravelEntitlements\Events\ReleaseRequested;
use LucaLongo\LaravelEntitlements\Exceptions\NoEntitlementAvailableException;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class SlotStrategy implements EntitlementStrategy
{
    public function __construct(public readonly bool $twoPhase = false) {}

    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage
    {
        return DB::transaction(function () use ($subscriber, $type, $subject): LicenseUsage {
            $license = License::query()
                ->where('subscriber_type', $subscriber->getMorphClass())
                ->where('subscriber_id', $subscriber->getKey())
                ->valid()
                ->ofType($type)
                ->whereColumn('slot_used', '<', 'slot_total')
                ->orderByRaw('ends_at IS NULL, ends_at ASC')
                ->lockForUpdate()
                ->first();

            if ($license === null) {
                throw NoEntitlementAvailableException::forSubscriber($subscriber, $type, 1);
            }

            $usage = LicenseUsage::query()->create([
                'license_id' => $license->getKey(),
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'amount' => 1,
                'status' => LicenseUsageStatus::Active,
            ]);

            $license->increment('slot_used');

            LicenseConsumed::dispatch($usage);

            return $usage;
        });
    }

    public function requestRelease(LicenseUsage $usage): void
    {
        if ($usage->status === LicenseUsageStatus::Released) {
            return;
        }

        if (! $this->twoPhase) {
            $this->forceRelease($usage);

            return;
        }

        $usage->update(['status' => LicenseUsageStatus::Releasing]);

        ReleaseRequested::dispatch($usage);
    }

    public function confirmRelease(LicenseUsage $usage): void
    {
        if ($usage->status === LicenseUsageStatus::Released) {
            return;
        }

        $this->forceRelease($usage);
    }

    public function forceRelease(LicenseUsage $usage): void
    {
        if ($usage->status === LicenseUsageStatus::Released) {
            return;
        }

        DB::transaction(function () use ($usage): void {
            $usage->update(['status' => LicenseUsageStatus::Released]);

            License::query()
                ->whereKey($usage->license_id)
                ->where('slot_used', '>', 0)
                ->decrement('slot_used');
        });

        LicenseReleased::dispatch($usage);
    }

    public function supportsTwoPhaseRelease(): bool
    {
        return $this->twoPhase;
    }
}
