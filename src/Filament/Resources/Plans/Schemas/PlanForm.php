<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Filament\Resources\Plans\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Models\PlanCategory;
use LucaLongo\LaravelEntitlements\Strategies\BooleanStrategy;

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
                            ->live()
                            ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                                if (self::isBooleanType($state)) {
                                    $set('quantity', 1);
                                    $set('enabled', true);
                                    $set('is_flexible', false);

                                    return;
                                }

                                if ((int) $get('quantity') < 1) {
                                    $set('quantity', 1);
                                }
                            })
                            ->native(false),

                        Group::make()
                            ->schema([
                                TextInput::make('quantity')
                                    ->numeric()
                                    ->minValue(fn (Get $get): ?int => self::isBooleanType($get('type')) ? null : 1)
                                    ->required(fn (Get $get): bool => ! self::isBooleanType($get('type')))
                                    ->hidden(fn (Get $get): bool => self::isBooleanType($get('type')))
                                    ->dehydrateStateUsing(fn (mixed $state, Get $get): int => self::normalizeQuantity($state, $get('type'))),

                                Toggle::make('enabled')
                                    ->label(__('Enabled'))
                                    ->default(true)
                                    ->afterStateHydrated(fn (Toggle $component, Get $get): mixed => $component->state((int) $get('quantity') > 0))
                                    ->afterStateUpdated(fn (bool $state, Set $set): mixed => $set('quantity', $state ? 1 : 0))
                                    ->visible(fn (Get $get): bool => self::isBooleanType($get('type')))
                                    ->dehydrated(false),
                            ]),

                        Toggle::make('is_flexible')
                            ->label(__('Flexible'))
                            ->hidden(fn (Get $get): bool => self::isBooleanType($get('type')))
                            ->dehydrateStateUsing(fn (bool $state, Get $get): bool => self::isBooleanType($get('type')) ? false : $state),
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
                $case->value => method_exists($case, 'getLabel') ? $case->getLabel() : __($case->name),
            ])
            ->all();
    }

    private static function isBooleanType(mixed $value): bool
    {
        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        /** @var class-string<EntitlementType>|null $enum */
        $enum = config('entitlements.type_enum');

        if ($enum === null) {
            return false;
        }

        foreach ($enum::cases() as $type) {
            if ((string) $type->value !== (string) $value) {
                continue;
            }

            return $type->strategy() instanceof BooleanStrategy;
        }

        return false;
    }

    private static function normalizeQuantity(mixed $quantity, mixed $type): int
    {
        $quantity = (int) $quantity;

        if (! self::isBooleanType($type)) {
            return $quantity;
        }

        return $quantity > 0 ? 1 : 0;
    }
}
