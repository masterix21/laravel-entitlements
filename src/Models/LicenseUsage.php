<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;

/**
 * @property int $id
 * @property int $license_id
 * @property string $subject_type
 * @property int $subject_id
 * @property int $amount
 * @property LicenseUsageStatus $status
 *
 * @method static Builder<static> open()
 */
final class LicenseUsage extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('entitlements.table_names.license_usages', 'entitlement_license_usages');
    }

    /**
     * @return BelongsTo<License, $this>
     */
    public function license(): BelongsTo
    {
        /** @var class-string<License> $model */
        $model = config('entitlements.models.license', License::class);

        return $this->belongsTo($model);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    protected function scopeOpen(Builder $query): void
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
