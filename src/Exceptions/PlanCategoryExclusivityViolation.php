<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class PlanCategoryExclusivityViolation extends RuntimeException
{
    public static function forCategory(int $categoryId): self
    {
        return new self("Subscriber already has an active plan in exclusive category [{$categoryId}].");
    }
}
