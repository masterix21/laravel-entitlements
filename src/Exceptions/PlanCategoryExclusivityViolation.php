<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use RuntimeException;

final class PlanCategoryExclusivityViolation extends RuntimeException
{
    public static function forCategory(int $categoryId): self
    {
        return new self((string) __('There is already an active plan in this category.'));
    }
}
