<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Events\LicenseReconciled;
use LucaLongo\LaravelEntitlements\Events\PlanAssigned;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanItem;

final class Entitlements
{
    /**
     * @param  array<int, int>  $quantityOverrides  keyed by PlanItem id, applied only to flexible items
     * @return Collection<int, License>
     */
    public function assignPlan(Model $subscriber, Plan $plan, CarbonInterface $startsAt, array $quantityOverrides = []): Collection
    {
        $endsAt = $plan->is_recurring
            ? null
            : $plan->billing_period->advance($startsAt);

        $licenses = DB::transaction(function () use ($subscriber, $plan, $startsAt, $endsAt, $quantityOverrides): Collection {
            return $plan->items->map(fn (PlanItem $item): License => $this->createLicenseFromItem(
                $subscriber,
                $plan,
                $item,
                $startsAt,
                $endsAt,
                $quantityOverrides,
            ));
        });

        PlanAssigned::dispatch($subscriber, $plan, $licenses);

        return $licenses;
    }

    public function consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage
    {
        return $type->strategy()->consume($subscriber, $type, $subject, $amount);
    }

    public function requestRelease(LicenseUsage $usage): void
    {
        $this->strategyFor($usage)->requestRelease($usage);
    }

    public function confirmRelease(LicenseUsage $usage): void
    {
        $this->strategyFor($usage)->confirmRelease($usage);
    }

    public function forceRelease(LicenseUsage $usage): void
    {
        $this->strategyFor($usage)->forceRelease($usage);
    }

    public function available(Model $subscriber, EntitlementType $type): int
    {
        return (int) License::query()
            ->where('subscriber_type', $subscriber->getMorphClass())
            ->where('subscriber_id', $subscriber->getKey())
            ->valid()
            ->ofType($type)
            ->get()
            ->sum(fn (License $license): int => $license->remaining);
    }

    public function capacity(Model $subscriber, EntitlementType $type): int
    {
        return (int) License::query()
            ->where('subscriber_type', $subscriber->getMorphClass())
            ->where('subscriber_id', $subscriber->getKey())
            ->valid()
            ->ofType($type)
            ->sum('slot_total');
    }

    public function can(Model $subscriber, EntitlementType $type, int $amount = 1): bool
    {
        return $this->available($subscriber, $type) >= $amount;
    }

    public function reconcile(License $license): void
    {
        $open = (int) $license->usages()->open()->sum('amount');

        $license->update([
            'slot_used' => $open,
            'last_checked_at' => now(),
        ]);

        LicenseReconciled::dispatch($license);
    }

    /**
     * @return array{reconciled: int}
     */
    public function recalculate(Model $subscriber): array
    {
        $licenses = License::query()
            ->where('subscriber_type', $subscriber->getMorphClass())
            ->where('subscriber_id', $subscriber->getKey())
            ->get();

        foreach ($licenses as $license) {
            $this->reconcile($license);
        }

        return ['reconciled' => $licenses->count()];
    }

    private function strategyFor(LicenseUsage $usage): \LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy
    {
        return $usage->license->type->strategy();
    }

    /**
     * @param  array<int, int>  $quantityOverrides
     */
    private function createLicenseFromItem(
        Model $subscriber,
        Plan $plan,
        PlanItem $item,
        CarbonInterface $startsAt,
        ?CarbonInterface $endsAt,
        array $quantityOverrides,
    ): License {
        $slotTotal = ($item->is_flexible && isset($quantityOverrides[$item->id]))
            ? (int) $quantityOverrides[$item->id]
            : $item->quantity;

        return License::create([
            'subscriber_type' => $subscriber->getMorphClass(),
            'subscriber_id' => $subscriber->getKey(),
            'plan_id' => $plan->id,
            'type' => $item->type->value,
            'slot_total' => $slotTotal,
            'slot_used' => 0,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
