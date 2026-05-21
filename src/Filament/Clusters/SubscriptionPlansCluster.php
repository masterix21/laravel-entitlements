<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Filament\Clusters;

use BackedEnum;
use CodeWithDennis\FilamentLucideIcons\Enums\LucideIcon;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use UnitEnum;

final class SubscriptionPlansCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = LucideIcon::Package;

    protected static string|UnitEnum|null $navigationGroup = 'Licensing';

    protected static ?int $navigationSort = 21;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getNavigationLabel(): string
    {
        return __('Subscription Plans');
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __('Subscription Plans');
    }
}
