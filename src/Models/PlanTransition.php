<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LucaLongo\LaravelEntitlements\Enums\PlanTransitionMode;
use LucaLongo\LaravelEntitlements\Enums\PlanTransitionStatus;

/**
 * @property int $id
 * @property int $anchor_license_id
 * @property int $target_plan_id
 * @property PlanTransitionMode $apply_mode
 * @property PlanTransitionStatus $status
 * @property CarbonInterface $scheduled_at
 * @property CarbonInterface|null $applied_at
 * @property array<int, int>|null $quantity_overrides
 * @property string|null $failure_reason
 * @property int|null $new_anchor_license_id
 * @property License $anchorLicense
 * @property License|null $newAnchorLicense
 * @property Plan $targetPlan
 *
 * @method static Builder<static> pending()
 * @method static Builder<static> due()
 */
class PlanTransition extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return (string) config('entitlements.table_names.plan_transitions', 'entitlement_plan_transitions');
    }

    protected function casts(): array
    {
        return [
            'apply_mode' => PlanTransitionMode::class,
            'status' => PlanTransitionStatus::class,
            'quantity_overrides' => 'array',
            'scheduled_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function anchorLicense(): BelongsTo
    {
        return $this->belongsTo(
            config('entitlements.models.license', License::class),
            'anchor_license_id',
        );
    }

    public function newAnchorLicense(): BelongsTo
    {
        return $this->belongsTo(
            config('entitlements.models.license', License::class),
            'new_anchor_license_id',
        );
    }

    public function targetPlan(): BelongsTo
    {
        return $this->belongsTo(
            config('entitlements.models.plan', Plan::class),
            'target_plan_id',
        );
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PlanTransitionStatus::Pending->value);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', PlanTransitionStatus::Pending->value)
            ->where('scheduled_at', '<=', now());
    }
}
