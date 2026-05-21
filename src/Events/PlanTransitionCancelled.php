<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LucaLongo\LaravelEntitlements\Models\PlanTransition;

final class PlanTransitionCancelled
{
    use Dispatchable;

    public function __construct(public PlanTransition $transition) {}
}
