<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Enums\PlanTransitionStatus;
use LucaLongo\LaravelEntitlements\Exceptions\InvalidEntitlementTypeException;

/**
 * @property int $id
 * @property string $subscriber_type
 * @property int $subscriber_id
 * @property int $plan_id
 * @property int|null $parent_id
 * @property EntitlementType $type
 * @property int $slot_total
 * @property int $slot_used
 * @property Carbon|null $last_checked_at
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property int $remaining
 *
 * @method static Builder<static> valid()
 * @method static Builder<static> ofType(EntitlementType $type)
 */
final class License extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('entitlements.table_names.licenses', 'entitlement_licenses');
    }

    public function subscriber(): MorphTo
    {
        return $this->morphTo();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(config('entitlements.models.plan', Plan::class));
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<LicenseUsage, $this>
     */
    public function usages(): HasMany
    {
        /** @var class-string<LicenseUsage> $model */
        $model = config('entitlements.models.license_usage', LicenseUsage::class);

        return $this->hasMany($model);
    }

    public function transitions(): HasMany
    {
        /** @var class-string<PlanTransition> $model */
        $model = config('entitlements.models.plan_transition', PlanTransition::class);

        return $this->hasMany($model, 'anchor_license_id');
    }

    public function pendingTransition(): ?PlanTransition
    {
        /** @var PlanTransition|null $transition */
        $transition = $this->transitions()
            ->where('status', PlanTransitionStatus::Pending->value)
            ->orderBy('scheduled_at')
            ->first();

        return $transition;
    }

    public function remaining(): Attribute
    {
        return Attribute::get(fn (): int => max(0, $this->slot_total - $this->slot_used));
    }

    public function isValid(): bool
    {
        $now = now();

        if ($this->starts_at->isAfter($now)) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isAfter($now);
    }

    protected function scopeValid(Builder $query): void
    {
        $now = now();

        $query->where('starts_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            });
    }

    protected function scopeOfType(Builder $query, EntitlementType $type): void
    {
        $query->where('type', $type->value);
    }

    protected function casts(): array
    {
        $enum = config('entitlements.type_enum');

        if ($enum === null) {
            throw InvalidEntitlementTypeException::missing();
        }

        return [
            'type' => $enum,
            'slot_total' => 'integer',
            'slot_used' => 'integer',
            'last_checked_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
