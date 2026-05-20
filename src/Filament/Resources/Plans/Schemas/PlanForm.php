<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Filament\Resources\Plans\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Models\PlanCategory;

final class PlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255)
                    ->formatStateUsing(self::toCurrentLocaleString(...)),

                Select::make('plan_category_id')
                    ->label(__('Category'))
                    ->options(fn (): array => PlanCategory::query()->pluck('name', 'id')->all())
                    ->native(false),

                Select::make('billing_period')
                    ->label(__('Billing Period'))
                    ->options([
                        BillingPeriod::Monthly->value => __('Monthly'),
                        BillingPeriod::Yearly->value => __('Yearly'),
                    ])
                    ->default(BillingPeriod::Monthly->value)
                    ->required()
                    ->native(false),

                Toggle::make('is_recurring')
                    ->label(__('Recurring'))
                    ->helperText(__('Recurring subscriptions have no end date until cancelled.')),

                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->default(true),

                Repeater::make('items')
                    ->label(__('Included Resources'))
                    ->relationship()
                    ->columnSpanFull()
                    ->minItems(1)
                    ->defaultItems(0)
                    ->table([
                        TableColumn::make(__('Type')),
                        TableColumn::make(__('Quantity')),
                        TableColumn::make(__('Flexible')),
                    ])
                    ->schema([
                        Select::make('type')
                            ->options(fn (): array => self::typeOptions())
                            ->required()
                            ->native(false),

                        TextInput::make('quantity')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText(__('A flexible quantity is set when the plan is assigned.')),

                        Toggle::make('is_flexible')
                            ->label(__('Flexible')),
                    ]),
            ]);
    }

    private static function toCurrentLocaleString(mixed $state): ?string
    {
        if (! is_array($state)) {
            return $state;
        }

        return $state[app()->getLocale()] ?? (reset($state) ?: null);
    }

    /** @return array<string, string> */
    private static function typeOptions(): array
    {
        $enum = config('entitlements.type_enum');

        if ($enum === null) {
            return [];
        }

        return collect($enum::cases())
            ->mapWithKeys(fn ($case): array => [
                $case->value => method_exists($case, 'getLabel') ? $case->getLabel() : $case->name,
            ])
            ->all();
    }
}
