<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Support\EntitlementTypeLabel;
use RuntimeException;

final class NoEntitlementAvailableException extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly ?Model $subscriber = null,
        public readonly ?EntitlementType $type = null,
        public readonly int $requested = 1,
    ) {
        parent::__construct($message);
    }

    public static function forSubscriber(Model $subscriber, EntitlementType $type, int $requested): self
    {
        return new self(
            (string) __('No ":type" entitlement is available (requested: :requested).', [
                'type' => EntitlementTypeLabel::resolve($type),
                'requested' => $requested,
            ]),
            $subscriber,
            $type,
            $requested,
        );
    }
}
