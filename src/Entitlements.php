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
use LucaLongo\LaravelEntitlements\Events\PlanTransitionCancelled;
use LucaLongo\LaravelEntitlements\Events\PlanTransitionFailed;
use LucaLongo\LaravelEntitlements\Events\PlanTransitionScheduled;
use LucaLongo\LaravelEntitlements\Exceptions\AnchorNotActiveForTransition;
use LucaLongo\LaravelEntitlements\Exceptions\IncompatiblePlanTransition;
use LucaLongo\LaravelEntitlements\Exceptions\InsufficientCapacityForTransition;
use LucaLongo\LaravelEntitlements\Exceptions\InvalidTransitionScheduledDate;
use LucaLongo\LaravelEntitlements\Exceptions\NoOpPlanTransition;
use LucaLongo\LaravelEntitlements\Exceptions\PlanCategoryExclusivityViolation;
use LucaLongo\LaravelEntitlements\Exceptions\TransitionAlreadyResolved;
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
        $this->assertCategoryExclusivity(
            $subscriber->getMorphClass(),
            $subscriber->getKey(),
            $plan,
        );

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
        DB::transaction(function () use ($license): void {
            $locked = License::query()
                ->whereKey($license->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $open = (int) $locked->usages()->open()->sum('amount');

            $locked->update([
                'slot_used' => $open,
                'last_checked_at' => now(),
            ]);
        });

        $license->refresh();

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
        ?CarbonInterface $scheduledAt = null,
    ): PlanTransition {
        $this->validateTransition($anchor, $newPlan, $quantityOverrides, $mode);

        $effectiveScheduledAt = $this->resolveScheduledAt($anchor, $mode, $scheduledAt);

        $transition = PlanTransition::create([
            'anchor_license_id' => $anchor->id,
            'target_plan_id' => $newPlan->id,
            'apply_mode' => $mode->value,
            'status' => PlanTransitionStatus::Pending->value,
            'scheduled_at' => $effectiveScheduledAt,
            'quantity_overrides' => $quantityOverrides ?: null,
        ]);

        if ($mode === PlanTransitionMode::Immediate) {
            $this->applyTransition($transition);

            return $transition->fresh();
        }

        PlanTransitionScheduled::dispatch($transition);

        return $transition;
    }

    private function resolveScheduledAt(
        License $anchor,
        PlanTransitionMode $mode,
        ?CarbonInterface $scheduledAt,
    ): CarbonInterface {
        return match ($mode) {
            PlanTransitionMode::Immediate => now(),
            PlanTransitionMode::EndOfPeriod => $anchor->ends_at ?? $anchor->next_billing_at,
            PlanTransitionMode::AtDate => $this->ensureFutureDate($scheduledAt),
        };
    }

    private function ensureFutureDate(?CarbonInterface $scheduledAt): CarbonInterface
    {
        if ($scheduledAt === null) {
            throw InvalidTransitionScheduledDate::missing();
        }

        if ($scheduledAt->lessThanOrEqualTo(now())) {
            throw InvalidTransitionScheduledDate::notInFuture();
        }

        return $scheduledAt;
    }

    public function cancelTransition(PlanTransition $transition): void
    {
        if ($transition->status !== PlanTransitionStatus::Pending) {
            throw TransitionAlreadyResolved::forStatus($transition->status->value);
        }

        $transition->update(['status' => PlanTransitionStatus::Cancelled->value]);
        PlanTransitionCancelled::dispatch($transition->fresh());
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
        $anchor = $transition->anchorLicense()->with('subscriber')->firstOrFail();
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
                    ->with('license')
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

        $newPlan->loadMissing(['items', 'category']);

        $newItemsByType = $newPlan->items->keyBy(fn (PlanItem $i) => $i->type->value);

        $groupLicenses = License::query()
            ->where('id', $anchor->id)
            ->orWhere('parent_id', $anchor->id)
            ->get();

        if ($newPlan->id === $anchor->plan_id && $this->isNoOpChange($groupLicenses, $newPlan, $quantityOverrides)) {
            throw NoOpPlanTransition::make();
        }

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

        $this->assertCategoryExclusivity(
            $anchor->subscriber_type,
            $anchor->subscriber_id,
            $newPlan,
            excludeAnchorId: $anchor->id,
        );
    }

    /**
     * @param  Collection<int, License>  $groupLicenses
     * @param  array<int, int>  $quantityOverrides
     */
    private function isNoOpChange(Collection $groupLicenses, Plan $newPlan, array $quantityOverrides): bool
    {
        $currentByType = $groupLicenses
            ->groupBy(fn (License $l) => $l->type->value)
            ->map(fn ($licenses) => (int) $licenses->sum('slot_total'));

        $newByType = $newPlan->items->mapWithKeys(function (PlanItem $item) use ($quantityOverrides): array {
            $effective = ($item->is_flexible && isset($quantityOverrides[$item->id]))
                ? (int) $quantityOverrides[$item->id]
                : (int) $item->quantity;

            return [$item->type->value => $effective];
        });

        if ($currentByType->keys()->sort()->values()->all() !== $newByType->keys()->sort()->values()->all()) {
            return false;
        }

        foreach ($newByType as $type => $value) {
            if (($currentByType[$type] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function assertCategoryExclusivity(
        string $subscriberType,
        int|string $subscriberId,
        Plan $plan,
        ?int $excludeAnchorId = null,
    ): void {
        $category = $plan->category;

        if ($category === null || $category->allows_multiple_active_plans) {
            return;
        }

        $conflict = License::query()
            ->where('subscriber_type', $subscriberType)
            ->where('subscriber_id', $subscriberId)
            ->whereNull('parent_id')
            ->when($excludeAnchorId !== null, fn ($q) => $q->where('id', '!=', $excludeAnchorId))
            ->whereHas('plan', fn ($q) => $q->where('plan_category_id', $category->id))
            ->where(function ($q): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->exists();

        if ($conflict) {
            throw PlanCategoryExclusivityViolation::forCategory($category->id);
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
