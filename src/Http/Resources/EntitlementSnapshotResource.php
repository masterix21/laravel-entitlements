<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LucaLongo\LaravelEntitlements\Data\EntitlementSnapshot;
use LucaLongo\LaravelEntitlements\Data\EntitlementTypeUsage;
use LucaLongo\LaravelEntitlements\Support\EntitlementTypeLabel;

/**
 * @mixin EntitlementSnapshot
 */
final class EntitlementSnapshotResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'types' => array_map(fn (EntitlementTypeUsage $usage): array => [
                'type' => $usage->type->value,
                'label' => EntitlementTypeLabel::resolve($usage->type),
                'capacity' => $usage->capacity,
                'used' => $usage->used,
                'available' => $usage->available,
            ], $this->resource->types),
        ];
    }
}
