<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Filament\RelationManagers;

use Awcodes\BadgeableColumn\Components\Badge;
use Awcodes\BadgeableColumn\Components\BadgeableColumn;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\Enums\LicenseUsageStatus;
use LucaLongo\LaravelEntitlements\Facades\Entitlements;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanItem;

final class LicensesRelationManager extends RelationManager
{
    protected static string $relationship = 'licenses';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Licenses');
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn (): string => __(':n active license(s)', [
                'n' => number_format($this->getOwnerRecord()->licenses()->valid()->whereNull('parent_id')->count()),
            ]))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->whereNull('parent_id')
                ->with(['plan.category', 'children']))
            ->columns([
                BadgeableColumn::make('plan.name')
                    ->label(__('Plan'))
                    ->description(fn (License $record): ?string => $record->plan?->category?->name)
                    ->suffixBadges([
                        Badge::make('billing_period')
                            ->label(fn (License $record): string => self::billingPeriodLabel($record->plan->billing_period))
                            ->color(fn (License $record): string => match ($record->plan->billing_period) {
                                BillingPeriod::Monthly => 'info',
                                BillingPeriod::Yearly => 'warning',
                            }),
                        Badge::make('is_recurring')
                            ->label(fn (License $record): string => $record->plan->is_recurring ? __('Recurring') : __('Fixed-term'))
                            ->color(fn (License $record): string => $record->plan->is_recurring ? 'success' : 'gray'),
                    ]),

                TextColumn::make('resource')
                    ->label(__('Resource'))
                    ->listWithLineBreaks()
                    ->state(fn (License $record): array => self::groupLicenses($record)
                        ->map(fn (License $license): string => self::resourceUsageLine($license))
                        ->all()),

                TextColumn::make('validity')
                    ->label(__('Validity'))
                    ->state(fn (License $record): string => $record->starts_at->translatedFormat('M j, Y'))
                    ->description(fn (License $record): string => $record->ends_at?->translatedFormat('M j, Y') ?? __('Perpetual')),
            ])
            ->headerActions([
                Action::make('assignPlan')
                    ->label(__('Assign Plan'))
                    ->form([
                        Grid::make(2)
                            ->schema([
                                Select::make('plan_id')
                                    ->label(__('Plan'))
                                    ->options(fn (): array => Plan::query()
                                        ->active()
                                        ->with('items')
                                        ->get()
                                        ->mapWithKeys(fn (Plan $plan): array => [$plan->id => self::planOptionLabel($plan)])
                                        ->all())
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        if (empty($state)) {
                                            $set('flexible_quantities', []);

                                            return;
                                        }
                                        $plan = Plan::query()->with('items')->find($state);
                                        if ($plan === null) {
                                            return;
                                        }
                                        $defaults = $plan->items
                                            ->where('is_flexible', true)
                                            ->mapWithKeys(fn (PlanItem $item): array => [$item->id => $item->quantity])
                                            ->all();
                                        $set('flexible_quantities', $defaults);
                                    })
                                    ->native(false)
                                    ->columnSpanFull(),

                                DatePicker::make('starts_at')
                                    ->label(__('Starts At'))
                                    ->default(now())
                                    ->required(),

                                DatePicker::make('ends_at')
                                    ->label(__('Expiration'))
                                    ->helperText(__('Leave empty to compute it from the plan.')),

                                Group::make()
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->schema(fn (Get $get): array => self::flexibleQuantityFields($get('plan_id'))),
                            ]),
                    ])
                    ->action(function (array $data): void {
                        $plan = Plan::query()->findOrFail($data['plan_id']);

                        $overrides = collect($data['flexible_quantities'] ?? [])
                            ->mapWithKeys(fn ($q, $id): array => [(int) $id => (int) $q])
                            ->all();

                        $licenses = Entitlements::assignPlan(
                            $this->getOwnerRecord(),
                            $plan,
                            CarbonImmutable::parse($data['starts_at']),
                            $overrides,
                        );

                        if (! empty($data['ends_at'])) {
                            $endsAt = CarbonImmutable::parse($data['ends_at']);

                            $licenses->each(fn ($license) => $license->update(['ends_at' => $endsAt]));
                        }

                        Notification::make()
                            ->title(__('Plan assigned'))
                            ->success()
                            ->send();
                    }),

                Action::make('recalculateUsages')
                    ->label(__('Recalculate Usages'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription(__('This reconciles all license counters for the subscriber.'))
                    ->action(function (): void {
                        $result = Entitlements::recalculate($this->getOwnerRecord());

                        Notification::make()
                            ->title(__('Usages recalculated'))
                            ->body(__(':n license(s) reconciled.', ['n' => $result['reconciled']]))
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->fillForm(function (License $record): array {
                        $licenses = self::groupLicenses($record);

                        return [
                            'starts_at' => $record->starts_at,
                            'ends_at' => $record->ends_at,
                            'slot_totals' => $licenses
                                ->mapWithKeys(fn (License $l): array => [$l->id => $l->slot_total])
                                ->all(),
                        ];
                    })
                    ->form(fn (License $record): array => array_merge(
                        [
                            DatePicker::make('starts_at')
                                ->label(__('Start Date'))
                                ->required(),

                            DatePicker::make('ends_at')
                                ->label(__('Expiration'))
                                ->helperText(__('Empty means the license never expires.')),
                        ],
                        self::groupLicenses($record)
                            ->map(fn (License $l): TextInput => TextInput::make("slot_totals.{$l->id}")
                                ->label(trans(':type quantity', ['type' => self::typeLabel($l->type)]))
                                ->numeric()
                                ->minValue(0)
                                ->required())
                            ->all(),
                    ))
                    ->using(function (License $record, array $data): License {
                        \Illuminate\Support\Facades\DB::transaction(function () use ($record, $data): void {
                            $startsAt = empty($data['starts_at']) ? null : \Carbon\CarbonImmutable::parse($data['starts_at']);
                            $endsAt = empty($data['ends_at']) ? null : \Carbon\CarbonImmutable::parse($data['ends_at']);

                            foreach (self::groupLicenses($record) as $license) {
                                $license->update([
                                    'starts_at' => $startsAt,
                                    'ends_at' => $endsAt,
                                    'slot_total' => (int) ($data['slot_totals'][$license->id] ?? $license->slot_total),
                                ]);
                            }
                        });

                        return $record->fresh();
                    }),

                Action::make('forceRelease')
                    ->label(__('Force Release Slot'))
                    ->icon('heroicon-o-lock-open')
                    ->iconButton()
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Force Release a Releasing Slot'))
                    ->form([
                        Select::make('license_usage_id')
                            ->label(__('Slot'))
                            ->options(fn ($record): array => $record->usages()
                                ->where('status', LicenseUsageStatus::Releasing)
                                ->pluck('id', 'id')
                                ->all())
                            ->required()
                            ->native(false),
                    ])
                    ->visible(fn ($record): bool => $record->usages()
                        ->where('status', LicenseUsageStatus::Releasing)
                        ->exists())
                    ->action(function (array $data, $record): void {
                        $usage = $record->usages()->findOrFail($data['license_usage_id']);

                        Entitlements::forceRelease($usage);

                        Notification::make()
                            ->title(__('Slot released'))
                            ->success()
                            ->send();
                    }),

                DeleteAction::make()
                    ->iconButton()
                    ->before(fn (License $record): mixed => $record->children()->delete()),
            ]);
    }

    /**
     * Build a numeric input per flexible item of the selected plan.
     *
     * @return array<int, TextInput>
     */
    private static function flexibleQuantityFields(mixed $planId): array
    {
        if (empty($planId)) {
            return [];
        }

        $plan = Plan::query()->with('items')->find($planId);

        if ($plan === null) {
            return [];
        }

        return $plan->items
            ->where('is_flexible', true)
            ->map(fn (PlanItem $item): TextInput => TextInput::make("flexible_quantities.{$item->id}")
                ->label(trans(':type quantity', ['type' => self::typeLabel($item->type)]))
                ->numeric()
                ->minValue(1)
                ->required()
                ->default($item->quantity))
            ->values()
            ->all();
    }

    /** @return Collection<int, License> */
    private static function groupLicenses(License $anchor): Collection
    {
        return collect([$anchor])->merge($anchor->children);
    }

    private static function resourceUsageLine(License $license): string
    {
        return number_format($license->slot_used).' / '.number_format($license->slot_total).' '.self::typeLabel($license->type);
    }

    private static function planOptionLabel(Plan $plan): string
    {
        $recurrence = $plan->is_recurring ? __('Recurring') : __('Fixed-term');

        $resources = $plan->items
            ->map(fn (PlanItem $item): string => number_format($item->quantity).' '.self::typeLabel($item->type))
            ->join(', ');

        $label = "{$plan->name} — ".self::billingPeriodLabel($plan->billing_period).", {$recurrence}";

        return $resources === '' ? $label : "{$label} — {$resources}";
    }

    private static function billingPeriodLabel(BillingPeriod $period): string
    {
        return match ($period) {
            BillingPeriod::Monthly => __('Monthly'),
            BillingPeriod::Yearly => __('Yearly'),
        };
    }

    private static function typeLabel(mixed $type): string
    {
        if ($type === null) {
            return '';
        }

        return method_exists($type, 'getLabel') ? $type->getLabel() : (isset($type->name) ? __($type->name) : (string) $type);
    }
}
