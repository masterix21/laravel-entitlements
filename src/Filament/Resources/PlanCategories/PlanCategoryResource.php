<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Filament\Resources\PlanCategories;

use BackedEnum;
use CodeWithDennis\FilamentLucideIcons\Enums\LucideIcon;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use LucaLongo\LaravelEntitlements\Filament\Concerns\HasTranslations;
use LucaLongo\LaravelEntitlements\Filament\Resources\PlanCategories\Pages\ListPlanCategories;
use LucaLongo\LaravelEntitlements\Filament\Resources\PlanCategories\Schemas\PlanCategoryForm;
use LucaLongo\LaravelEntitlements\Filament\Resources\PlanCategories\Tables\PlanCategoriesTable;
use LucaLongo\LaravelEntitlements\Models\PlanCategory;
use UnitEnum;

final class PlanCategoryResource extends Resource
{
    use HasTranslations;

    protected static ?string $model = PlanCategory::class;

    protected static string|BackedEnum|null $navigationIcon = LucideIcon::FolderTree;

    protected static string|UnitEnum|null $navigationGroup = 'Licensing';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('Plan Category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Plan Categories');
    }

    public static function form(Schema $schema): Schema
    {
        return PlanCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlanCategoriesTable::configure($table);
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
            'index' => ListPlanCategories::route('/'),
        ];
    }
}
