<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use Spatie\Translatable\HasTranslations;

/**
 * @property int $id
 * @property int|null $plan_category_id
 * @property string $name
 * @property BillingPeriod $billing_period
 * @property bool $is_recurring
 * @property bool $is_active
 * @property PlanCategory|null $category
 *
 * @method static Builder<static> active()
 */
final class Plan extends Model
{
    use HasFactory;
    use HasTranslations;

    public array $translatable = ['name'];

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('entitlements.table_names.plans', 'entitlement_plans');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(
            config('entitlements.models.plan_category', PlanCategory::class),
            'plan_category_id',
        );
    }

    /**
     * @return HasMany<PlanItem, $this>
     */
    public function items(): HasMany
    {
        /** @var class-string<PlanItem> $model */
        $model = config('entitlements.models.plan_item', PlanItem::class);

        return $this->hasMany($model);
    }

    protected function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    protected function casts(): array
    {
        return [
            'billing_period' => BillingPeriod::class,
            'is_recurring' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
