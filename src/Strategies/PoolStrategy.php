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
use LucaLongo\LaravelEntitlements\Exceptions\NoEntitlementAvailableException;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

final class PoolStrategy implements EntitlementStrategy
{
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

                $usage = $license->usages()->create([
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => $subject->getKey(),
                    'amount' => $take,
                    'status' => LicenseUsageStatus::Active,
                ]);

                $license->increment('slot_used', $take);
                $remaining -= $take;

                $primaryUsage ??= $usage;

                LicenseConsumed::dispatch($usage);
            }

            return $primaryUsage;
        });
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
        if ($usage->status === LicenseUsageStatus::Released) {
            return;
        }

        DB::transaction(function () use ($usage): void {
            $amount = $usage->amount;
            $usage->update(['status' => LicenseUsageStatus::Released]);

            License::query()
                ->whereKey($usage->license_id)
                ->where('slot_used', '>=', $amount)
                ->decrement('slot_used', $amount);
        });

        LicenseReleased::dispatch($usage);
    }

    public function supportsTwoPhaseRelease(): bool
    {
        return false;
    }
}
