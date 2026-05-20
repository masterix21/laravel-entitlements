<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use RuntimeException;

final class NoEntitlementAvailableException extends RuntimeException
{
    public static function forSubscriber(Model $subscriber, EntitlementType $type, int $requested): self
    {
        return new self(sprintf(
            'No entitlement available for subscriber [%s#%s] of type [%s] (requested: %d).',
            $subscriber::class,
            (string) $subscriber->getKey(),
            $type->value,
            $requested,
        ));
    }
}
