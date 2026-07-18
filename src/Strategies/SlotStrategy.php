<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Strategies;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    /**
     * Consumes $amount slots as individual single-slot usages for the same
     * subject, spreading across licenses (soonest-expiring first) when needed.
     * Returns the first usage created.
     */
    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        return DB::transaction(function () use ($subscriber, $type, $subject, $amount): LicenseUsage {
            $licenses = License::query()
                ->where('subscriber_type', $subscriber->getMorphClass())
                ->where('subscriber_id', $subscriber->getKey())
                ->valid()
                ->ofType($type)
                ->whereColumn('slot_used', '<', 'slot_total')
                ->orderByRaw('ends_at IS NULL, ends_at ASC')
                ->lockForUpdate()
                ->get();

            $available = $licenses->sum(fn (License $license): int => $license->remaining);

            if ($available < $amount) {
                throw NoEntitlementAvailableException::forSubscriber($subscriber, $type, $amount);
            }

            $remaining = $amount;
            $primaryUsage = null;

            foreach ($licenses as $license) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, $license->remaining);

                if ($take <= 0) {
                    continue;
                }

                for ($i = 0; $i < $take; $i++) {
                    $usage = LicenseUsage::query()->create([
                        'license_id' => $license->getKey(),
                        'subject_type' => $subject->getMorphClass(),
                        'subject_id' => $subject->getKey(),
                        'amount' => 1,
                        'status' => LicenseUsageStatus::Active,
                    ]);

                    $primaryUsage ??= $usage;

                    LicenseConsumed::dispatch($usage);
                }

                $license->increment('slot_used', $take);
                $remaining -= $take;
            }

            if (! $primaryUsage instanceof LicenseUsage) {
                throw NoEntitlementAvailableException::forSubscriber($subscriber, $type, $amount);
            }

            return $primaryUsage;
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

        $released = DB::transaction(function () use ($usage): bool {
            $locked = LicenseUsage::query()
                ->whereKey($usage->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            if ($locked->status === LicenseUsageStatus::Released) {
                return false;
            }

            $locked->update(['status' => LicenseUsageStatus::Released]);

            $affected = License::query()
                ->whereKey($locked->license_id)
                ->where('slot_used', '>', 0)
                ->decrement('slot_used');

            if ($affected === 0) {
                Log::warning('Slot decrement skipped on release: license counters out of sync, reconcile needed.', [
                    'license_id' => $locked->license_id,
                    'license_usage_id' => $locked->getKey(),
                ]);
            }

            return true;
        });

        if (! $released) {
            return;
        }

        $usage->refresh();

        LicenseReleased::dispatch($usage);
    }

    public function supportsTwoPhaseRelease(): bool
    {
        return $this->twoPhase;
    }
}
