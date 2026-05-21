<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Commands;

use Illuminate\Console\Command;
use LucaLongo\LaravelEntitlements\Entitlements;

class ApplyDuePlanTransitionsCommand extends Command
{
    protected $signature = 'entitlements:apply-transitions';

    protected $description = 'Apply all due plan transitions.';

    public function handle(Entitlements $entitlements): int
    {
        $count = $entitlements->applyDueTransitions();
        $this->info("Applied {$count} plan transition(s).");

        return self::SUCCESS;
    }
}
