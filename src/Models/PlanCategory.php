<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

final class PlanCategory extends Model
{
    use HasFactory;
    use HasTranslations;

    public array $translatable = ['name'];

    protected $guarded = [];

    protected $attributes = [
        'allows_multiple_active_plans' => true,
    ];

    public function getTable(): string
    {
        return (string) config('entitlements.table_names.plan_categories', 'entitlement_plan_categories');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(config('entitlements.models.plan', Plan::class));
    }

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'allows_multiple_active_plans' => 'boolean',
        ];
    }
}
