<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;
use LucaLongo\LaravelEntitlements\Models\Plan;

final class PlanAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly Model $subscriber,
        public readonly Plan $plan,
        public readonly Collection $licenses,
    ) {}
}
