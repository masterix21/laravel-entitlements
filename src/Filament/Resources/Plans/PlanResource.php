<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Filament\Resources\Plans;

use BackedEnum;
use CodeWithDennis\FilamentLucideIcons\Enums\LucideIcon;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use LucaLongo\LaravelEntitlements\Filament\Clusters\SubscriptionPlansCluster;
use LucaLongo\LaravelEntitlements\Filament\Concerns\HasTranslations;
use LucaLongo\LaravelEntitlements\Filament\Resources\Plans\Pages\ListPlans;
use LucaLongo\LaravelEntitlements\Filament\Resources\Plans\Schemas\PlanForm;
use LucaLongo\LaravelEntitlements\Filament\Resources\Plans\Tables\PlansTable;
use LucaLongo\LaravelEntitlements\Models\Plan;

final class PlanResource extends Resource
{
    use HasTranslations;

    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = LucideIcon::Package;

    protected static ?string $cluster = SubscriptionPlansCluster::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('Plan');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Plans');
    }

    public static function getNavigationLabel(): string
    {
        return __('Plans');
    }

    public static function form(Schema $schema): Schema
    {
        return PlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
        ];
    }
}
