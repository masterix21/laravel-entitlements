<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Enums\PlanTransitionMode;
use LucaLongo\LaravelEntitlements\Enums\PlanTransitionStatus;
use LucaLongo\LaravelEntitlements\Events\LicenseReconciled;
use LucaLongo\LaravelEntitlements\Events\PlanAssigned;
use LucaLongo\LaravelEntitlements\Events\PlanTransitionApplied;
use LucaLongo\LaravelEntitlements\Events\PlanTransitionFailed;
use LucaLongo\LaravelEntitlements\Events\PlanTransitionScheduled;
use LucaLongo\LaravelEntitlements\Exceptions\AnchorNotActiveForTransition;
use LucaLongo\LaravelEntitlements\Exceptions\EndOfPeriodTransitionRequiresEndsAt;
use LucaLongo\LaravelEntitlements\Exceptions\IncompatiblePlanTransition;
use LucaLongo\LaravelEntitlements\Exceptions\InsufficientCapacityForTransition;
use LucaLongo\LaravelEntitlements\Exceptions\PlanCategoryExclusivityViolation;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanItem;
use LucaLongo\LaravelEntitlements\Models\PlanTransition;

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
            $created = new Collection;
            $anchorId = null;

            foreach ($plan->items as $item) {
                $license = $this->createLicenseFromItem(
                    $subscriber,
                    $plan,
                    $item,
                    $startsAt,
                    $endsAt,
                    $anchorId,
                    $quantityOverrides,
                );

                $anchorId ??= $license->id;
                $created->push($license);
            }

            return $created;
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

    /**
     * @param  array<int, int>  $quantityOverrides
     */
    public function changePlan(
        License $anchor,
        Plan $newPlan,
        PlanTransitionMode $mode,
        array $quantityOverrides = [],
    ): PlanTransition {
        $this->validateTransition($anchor, $newPlan, $quantityOverrides, $mode);

        $scheduledAt = $mode === PlanTransitionMode::Immediate
            ? now()
            : $anchor->ends_at;

        $transition = PlanTransition::create([
            'anchor_license_id' => $anchor->id,
            'target_plan_id' => $newPlan->id,
            'apply_mode' => $mode->value,
            'status' => PlanTransitionStatus::Pending->value,
            'scheduled_at' => $scheduledAt,
            'quantity_overrides' => $quantityOverrides ?: null,
        ]);

        if ($mode === PlanTransitionMode::Immediate) {
            $this->applyTransition($transition);

            return $transition->fresh();
        }

        PlanTransitionScheduled::dispatch($transition);

        return $transition;
    }

    public function applyDueTransitions(): int
    {
        $applied = 0;

        PlanTransition::due()->orderBy('scheduled_at')->get()->each(function (PlanTransition $transition) use (&$applied): void {
            try {
                $this->applyTransition($transition);
                $applied++;
            } catch (\Throwable) {
                // failure already recorded by applyTransition
            }
        });

        return $applied;
    }

    private function applyTransition(PlanTransition $transition): void
    {
        /** @var License $anchor */
        $anchor = $transition->anchorLicense()->firstOrFail();
        /** @var Plan $newPlan */
        $newPlan = $transition->targetPlan()->with('items')->firstOrFail();
        $overrides = $transition->quantity_overrides ?? [];

        try {
            DB::transaction(function () use ($transition, $anchor, $newPlan, $overrides): void {
                $this->validateTransition($anchor, $newPlan, $overrides, PlanTransitionMode::Immediate, skipAnchorActiveCheck: $transition->apply_mode === PlanTransitionMode::EndOfPeriod);

                $subscriber = $anchor->subscriber;
                $transitionAt = now();
                $endsAt = $newPlan->is_recurring ? null : $newPlan->billing_period->advance($transitionAt);

                $newAnchorId = null;
                $newLicensesByType = [];
                foreach ($newPlan->items as $item) {
                    $license = $this->createLicenseFromItem(
                        $subscriber,
                        $newPlan,
                        $item,
                        $transitionAt,
                        $endsAt,
                        $newAnchorId,
                        $overrides,
                    );
                    $newAnchorId ??= $license->id;
                    $newLicensesByType[$item->type->value] = $license;
                }

                $oldGroupIds = License::query()
                    ->where('id', $anchor->id)
                    ->orWhere('parent_id', $anchor->id)
                    ->pluck('id');

                $usages = LicenseUsage::query()
                    ->whereIn('license_id', $oldGroupIds)
                    ->open()
                    ->get();

                foreach ($usages as $usage) {
                    /** @var License $oldLicense */
                    $oldLicense = $usage->license;
                    $target = $newLicensesByType[$oldLicense->type->value] ?? null;
                    if ($target === null) {
                        throw IncompatiblePlanTransition::forType((string) $oldLicense->type->value);
                    }
                    $usage->update(['license_id' => $target->id]);
                }

                License::query()
                    ->whereIn('id', $oldGroupIds)
                    ->update(['ends_at' => $transitionAt]);

                foreach ($newLicensesByType as $license) {
                    $this->reconcile($license->fresh());
                }

                $transition->update([
                    'status' => PlanTransitionStatus::Applied->value,
                    'applied_at' => $transitionAt,
                    'new_anchor_license_id' => $newAnchorId,
                ]);
            });

            /** @var PlanTransition $fresh */
            $fresh = $transition->fresh();
            /** @var License $newAnchor */
            $newAnchor = $fresh->newAnchorLicense;
            /** @var License $oldAnchorFresh */
            $oldAnchorFresh = $anchor->fresh();
            PlanTransitionApplied::dispatch($fresh, $oldAnchorFresh, $newAnchor);
        } catch (\Throwable $e) {
            $transition->update([
                'status' => PlanTransitionStatus::Failed->value,
                'failure_reason' => $e->getMessage(),
            ]);
            PlanTransitionFailed::dispatch($transition->fresh(), $e);
            throw $e;
        }
    }

    /**
     * @param  array<int, int>  $quantityOverrides
     */
    private function validateTransition(
        License $anchor,
        Plan $newPlan,
        array $quantityOverrides,
        PlanTransitionMode $mode,
        bool $skipAnchorActiveCheck = false,
    ): void {
        if ($anchor->parent_id !== null) {
            throw AnchorNotActiveForTransition::notAnchor($anchor->id);
        }

        if (! $skipAnchorActiveCheck && $anchor->ends_at !== null && $anchor->ends_at->lessThanOrEqualTo(now())) {
            throw AnchorNotActiveForTransition::expired($anchor->id);
        }

        if ($mode === PlanTransitionMode::EndOfPeriod && $anchor->ends_at === null) {
            throw EndOfPeriodTransitionRequiresEndsAt::make();
        }

        $newPlan->loadMissing(['items', 'category']);

        $newItemsByType = $newPlan->items->keyBy(fn (PlanItem $i) => $i->type->value);

        $groupLicenses = License::query()
            ->where('id', $anchor->id)
            ->orWhere('parent_id', $anchor->id)
            ->get();

        $groupLicenseIds = $groupLicenses->pluck('id');

        $openUsageLicenseIds = LicenseUsage::query()
            ->whereIn('license_id', $groupLicenseIds)
            ->open()
            ->pluck('license_id')
            ->unique();

        $openUsageTypes = $groupLicenses
            ->whereIn('id', $openUsageLicenseIds->all())
            ->map(fn (License $l) => $l->type->value)
            ->unique();

        foreach ($openUsageTypes as $type) {
            if (! $newItemsByType->has($type)) {
                throw IncompatiblePlanTransition::forType((string) $type);
            }
        }

        $usedByType = $groupLicenses
            ->groupBy(fn (License $l) => $l->type->value)
            ->map(fn ($licenses) => $licenses->sum('slot_used'));

        foreach ($usedByType as $type => $used) {
            if (! $newItemsByType->has($type)) {
                continue;
            }

            /** @var PlanItem $item */
            $item = $newItemsByType->get($type);
            $capacity = ($item->is_flexible && isset($quantityOverrides[$item->id]))
                ? (int) $quantityOverrides[$item->id]
                : $item->quantity;

            if ((int) $used > $capacity) {
                throw InsufficientCapacityForTransition::forType((string) $type, (int) $used, $capacity);
            }
        }

        $category = $newPlan->category;
        if ($category !== null && ! $category->allows_multiple_active_plans) {
            $conflict = License::query()
                ->where('subscriber_type', $anchor->subscriber_type)
                ->where('subscriber_id', $anchor->subscriber_id)
                ->whereNull('parent_id')
                ->where('id', '!=', $anchor->id)
                ->whereHas('plan', fn ($q) => $q->where('plan_category_id', $category->id))
                ->where(function ($q): void {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                })
                ->exists();

            if ($conflict) {
                throw PlanCategoryExclusivityViolation::forCategory($category->id);
            }
        }
    }

    private function strategyFor(LicenseUsage $usage): EntitlementStrategy
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
        ?int $parentId,
        array $quantityOverrides,
    ): License {
        $slotTotal = ($item->is_flexible && isset($quantityOverrides[$item->id]))
            ? (int) $quantityOverrides[$item->id]
            : $item->quantity;

        return License::create([
            'subscriber_type' => $subscriber->getMorphClass(),
            'subscriber_id' => $subscriber->getKey(),
            'plan_id' => $plan->id,
            'parent_id' => $parentId,
            'type' => $item->type->value,
            'slot_total' => $slotTotal,
            'slot_used' => 0,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }
}
