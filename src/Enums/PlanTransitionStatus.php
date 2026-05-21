<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Enums;

enum PlanTransitionStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
