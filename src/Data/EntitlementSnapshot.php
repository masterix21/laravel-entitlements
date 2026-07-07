<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Data;

use Illuminate\Database\Eloquent\Model;

final readonly class EntitlementSnapshot
{
    /**
     * @param  array<int, EntitlementTypeUsage>  $types
     */
    public function __construct(
        public Model $subscriber,
        public array $types,
    ) {}
}
