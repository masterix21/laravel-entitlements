<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Support\EntitlementTypeLabel;

/**
 * @mixin License
 */
final class LicenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'type' => $this->type->value,
            'label' => EntitlementTypeLabel::resolve($this->type),
            'slot_total' => $this->slot_total,
            'slot_used' => $this->slot_used,
            'remaining' => $this->remaining,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_valid' => $this->isValid(),
        ];
    }
}
