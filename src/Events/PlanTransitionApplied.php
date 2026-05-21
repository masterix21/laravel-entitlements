<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\PlanTransition;

final class PlanTransitionApplied
{
    use Dispatchable;

    public function __construct(
        public PlanTransition $transition,
        public License $oldAnchor,
        public License $newAnchor,
    ) {}
}
