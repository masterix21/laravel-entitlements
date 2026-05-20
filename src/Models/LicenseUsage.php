<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;

final class LicenseUsage extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('entitlements.table_names.license_usages');
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(config('entitlements.models.license', License::class));
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    #[Scope]
    public function open(Builder $query): void
    {
        $query->where('status', '!=', LicenseUsageStatus::Released);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => LicenseUsageStatus::class,
        ];
    }
}
