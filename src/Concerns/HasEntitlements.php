<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use LucaLongo\LaravelEntitlements\Models\License;

trait HasEntitlements
{
    public function licenses(): MorphMany
    {
        return $this->morphMany(
            config('entitlements.models.license', License::class),
            'subscriber',
        );
    }
}
