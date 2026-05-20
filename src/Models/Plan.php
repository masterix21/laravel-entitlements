<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use Spatie\Translatable\HasTranslations;

final class Plan extends Model
{
    use HasFactory;
    use HasTranslations;

    public array $translatable = ['name'];

    protected $guarded = [];

    public function getTable(): string
    {
        return config('entitlements.table_names.plans');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(
            config('entitlements.models.plan_category', PlanCategory::class),
            'plan_category_id',
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(config('entitlements.models.plan_item', PlanItem::class));
    }

    #[Scope]
    public function active(Builder $query): void
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
