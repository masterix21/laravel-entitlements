<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LucaLongo\LaravelEntitlements\Exceptions\InvalidEntitlementTypeException;

final class PlanItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('entitlements.table_names.plan_items');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(config('entitlements.models.plan', Plan::class));
    }

    protected function casts(): array
    {
        $enum = config('entitlements.type_enum');

        if ($enum === null) {
            throw InvalidEntitlementTypeException::missing();
        }

        return [
            'quantity' => 'integer',
            'is_flexible' => 'boolean',
            'type' => $enum,
        ];
    }
}
