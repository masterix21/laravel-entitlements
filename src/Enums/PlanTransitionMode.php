<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Enums;

enum PlanTransitionMode: string
{
    case Immediate = 'immediate';
    case EndOfPeriod = 'end_of_period';
    case AtDate = 'at_date';
}
