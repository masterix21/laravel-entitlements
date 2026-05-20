<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Filament\Resources\Plans\Tables;

use Awcodes\BadgeableColumn\Components\Badge;
use Awcodes\BadgeableColumn\Components\BadgeableColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanCategory;
use LucaLongo\LaravelEntitlements\Models\PlanItem;

final class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['category', 'items']))
            ->columns([
                BadgeableColumn::make('name')
                    ->label(__('Name'))
                    ->description(fn (Plan $record): ?string => $record->category?->name)
                    ->searchable()
                    ->sortable()
                    ->suffixBadges([
                        Badge::make('billing_period')
                            ->label(fn (Plan $record): string => self::billingPeriodLabel($record->billing_period))
                            ->color(fn (Plan $record): string => match ($record->billing_period) {
                                BillingPeriod::Monthly => 'info',
                                BillingPeriod::Yearly => 'warning',
                            }),
                        Badge::make('is_recurring')
                            ->label(fn (Plan $record): string => $record->is_recurring ? __('Recurring') : __('Fixed-term'))
                            ->color(fn (Plan $record): string => $record->is_recurring ? 'success' : 'gray'),
                    ]),

                TextColumn::make('items')
                    ->label(__('Resources'))
                    ->listWithLineBreaks()
                    ->state(fn (Plan $record): array => $record->items
                        ->map(fn (PlanItem $item): string => number_format($item->quantity).' '.(method_exists($item->type, 'getLabel') ? $item->type->getLabel() : $item->type->name))
                        ->all())
                    ->placeholder(__('No resources')),

                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('billing_period')
                    ->label(__('Billing Period'))
                    ->options([
                        BillingPeriod::Monthly->value => __('Monthly'),
                        BillingPeriod::Yearly->value => __('Yearly'),
                    ]),

                SelectFilter::make('plan_category_id')
                    ->label(__('Category'))
                    ->options(fn (): array => PlanCategory::query()->pluck('name', 'id')->all()),

                TernaryFilter::make('is_recurring')
                    ->label(__('Recurring')),

                TernaryFilter::make('is_active')
                    ->label(__('Active')),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function billingPeriodLabel(BillingPeriod $period): string
    {
        return match ($period) {
            BillingPeriod::Monthly => __('Monthly'),
            BillingPeriod::Yearly => __('Yearly'),
        };
    }
}
