<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Filament\Resources\PlanCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

final class PlanCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255)
                    ->formatStateUsing(self::toCurrentLocaleString(...)),

                TextInput::make('sort')
                    ->label(__('Sort Order'))
                    ->numeric()
                    ->default(0)
                    ->required(),

                Toggle::make('allows_multiple_active_plans')
                    ->label(__('Allow multiple active plans'))
                    ->helperText(__('When disabled, a subscriber can have at most one active plan in this category.'))
                    ->default(true),
            ]);
    }

    private static function toCurrentLocaleString(mixed $state): ?string
    {
        if (! is_array($state)) {
            return $state;
        }

        return $state[app()->getLocale()] ?? (reset($state) ?: null);
    }
}
