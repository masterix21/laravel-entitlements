<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use LucaLongo\LaravelEntitlements\Filament\Resources\PlanCategories\PlanCategoryResource;
use LucaLongo\LaravelEntitlements\Filament\Resources\Plans\PlanResource;

final class EntitlementsPlugin implements Plugin
{
    private bool $registerPlanResource = true;

    private bool $registerPlanCategoryResource = true;

    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'laravel-entitlements';
    }

    public function withoutPlanResource(): self
    {
        $this->registerPlanResource = false;

        return $this;
    }

    public function withoutPlanCategoryResource(): self
    {
        $this->registerPlanCategoryResource = false;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $resources = [];

        if ($this->registerPlanCategoryResource) {
            $resources[] = PlanCategoryResource::class;
        }

        if ($this->registerPlanResource) {
            $resources[] = PlanResource::class;
        }

        $panel->resources($resources);
    }

    public function boot(Panel $panel): void
    {
    }
}
