<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LucaLongo\LaravelEntitlements\Models\PlanTransition;
use Throwable;

final class PlanTransitionFailed
{
    use Dispatchable;

    public function __construct(
        public PlanTransition $transition,
        public Throwable $exception,
    ) {}
}
