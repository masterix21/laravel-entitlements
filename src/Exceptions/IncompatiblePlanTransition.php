<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class IncompatiblePlanTransition extends RuntimeException
{
    public static function forType(string $type): self
    {
        return new self("Target plan does not contain a PlanItem for type [{$type}] while open usages exist.");
    }
}
