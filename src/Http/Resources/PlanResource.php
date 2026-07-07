<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanItem;
use LucaLongo\LaravelEntitlements\Support\EntitlementTypeLabel;

/**
 * @mixin Plan
 */
final class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'billing_period' => $this->billing_period->value,
            'is_recurring' => $this->is_recurring,
            'is_active' => $this->is_active,
            'items' => $this->items->map(fn (PlanItem $item): array => [
                'type' => $item->type->value,
                'label' => EntitlementTypeLabel::resolve($item->type),
                'quantity' => $item->quantity,
                'is_flexible' => $item->is_flexible,
            ])->all(),
        ];
    }
}
