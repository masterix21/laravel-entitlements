<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Exceptions\InvalidEntitlementTypeException;

final class License extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('entitlements.table_names.licenses');
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

    public function usages(): HasMany
    {
        return $this->hasMany(config('entitlements.models.license_usage', LicenseUsage::class));
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

    #[Scope]
    public function valid(Builder $query): void
    {
        $now = now();

        $query->where('starts_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            });
    }

    #[Scope]
    public function ofType(Builder $query, EntitlementType $type): void
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
