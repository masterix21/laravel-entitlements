<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Filament\Concerns;

use Filament\Resources\Resource;

/** @mixin Resource */
trait HasTranslations
{
    public static function getModelLabel(): string
    {
        return __(parent::getModelLabel());
    }

    public static function getPluralModelLabel(): string
    {
        return __(parent::getPluralModelLabel());
    }

    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }

    public static function getNavigationGroup(): ?string
    {
        $group = parent::getNavigationGroup();

        return $group ? __($group) : null;
    }

    public static function getBreadcrumb(): string
    {
        return __(parent::getBreadcrumb());
    }

    public static function getNavigationBadge(): ?string
    {
        $badge = parent::getNavigationBadge();

        return $badge ? __($badge) : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        $tooltip = parent::getNavigationBadgeTooltip();

        return $tooltip ? __($tooltip) : null;
    }

    public static function getTitle(): string
    {
        return __(parent::getTitle());
    }

    public static function getNavigationItem(): ?string
    {
        $item = parent::getNavigationItem();

        return $item ? __($item) : null;
    }
}
