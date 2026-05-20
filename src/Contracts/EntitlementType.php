<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Contracts;

use BackedEnum;

interface EntitlementType extends BackedEnum
{
    public function strategy(): EntitlementStrategy;
}
