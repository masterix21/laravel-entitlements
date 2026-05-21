<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Filament\RelationManagers;

use Awcodes\BadgeableColumn\Components\Badge;
use Awcodes\BadgeableColumn\Components\BadgeableColumn;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
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
use LucaLongo\LaravelEntitlements\Enums\PlanTransitionMode;
use LucaLongo\LaravelEntitlements\Exceptions\AnchorNotActiveForTransition;
use LucaLongo\LaravelEntitlements\Exceptions\IncompatiblePlanTransition;
use LucaLongo\LaravelEntitlements\Exceptions\InsufficientCapacityForTransition;
use LucaLongo\LaravelEntitlements\Exceptions\InvalidTransitionScheduledDate;
use LucaLongo\LaravelEntitlements\Exceptions\NoOpPlanTransition;
use LucaLongo\LaravelEntitlements\Exceptions\PlanCategoryExclusivityViolation;
use LucaLongo\LaravelEntitlements\Exceptions\TransitionAlreadyResolved;
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
                ->with(['plan.category', 'children'])
                ->orderByRaw('CASE WHEN ends_at IS NULL THEN 1 WHEN ends_at > ? THEN 0 ELSE 2 END', [now()])
                ->orderBy('ends_at'))
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
                            ->color(fn (License $record): string => $record->plan->is_recurring ? 'success' : 'gray')
                            ->visible(fn (License $record): bool => $record->ends_at === null || $record->ends_at->isFuture()),
                        Badge::make('expired')
                            ->label(__('Expired'))
                            ->color('danger')
                            ->visible(fn (License $record): bool => $record->ends_at !== null && $record->ends_at->isPast()),
                    ]),

                TextColumn::make('resource')
                    ->label(__('Resource'))
                    ->listWithLineBreaks()
                    ->state(fn (License $record): array => self::groupLicenses($record)
                        ->map(fn (License $license): string => self::resourceUsageLine($license))
                        ->all()),

                BadgeableColumn::make('validity')
                    ->label(__('Validity'))
                    ->state(fn (License $record): string => $record->starts_at->translatedFormat('M j, Y'))
                    ->suffixBadges([
                        Badge::make('expiration')
                            ->label(fn (License $record): string => $record->ends_at === null
                                ? __('Perpetual')
                                : $record->ends_at->translatedFormat('M j, Y'))
                            ->color(fn (License $record): string => match (true) {
                                $record->ends_at === null => 'success',
                                $record->ends_at->isPast() => 'danger',
                                default => 'warning',
                            }),
                    ]),

                TextColumn::make('pending_transition')
                    ->label('')
                    ->state(function (License $record): ?string {
                        if ($record->parent_id !== null) {
                            return null;
                        }

                        $pending = $record->pendingTransition();

                        if ($pending === null) {
                            return null;
                        }

                        return __('Pending plan change', [
                            'plan' => $pending->targetPlan->name,
                            'date' => $pending->scheduled_at->toDayDateTimeString(),
                        ]);
                    })
                    ->badge()
                    ->color('warning'),
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

                        try {
                            $licenses = Entitlements::assignPlan(
                                $this->getOwnerRecord(),
                                $plan,
                                CarbonImmutable::parse($data['starts_at']),
                                $overrides,
                            );
                        } catch (PlanCategoryExclusivityViolation $e) {
                            self::notifyDomainError($e);

                            return;
                        }

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
                Action::make('changePlan')
                    ->label(__('Change plan'))
                    ->icon('heroicon-o-arrows-right-left')
                    ->iconButton()
                    ->visible(fn (License $record): bool => $record->parent_id === null
                        && ($record->ends_at === null || $record->ends_at->isFuture()))
                    ->form(fn (License $record): array => [
                        Grid::make(2)
                            ->schema([
                                Select::make('target_plan_id')
                                    ->label(__('Plan'))
                                    ->options(fn (): array => Plan::query()
                                        ->active()
                                        ->with('items')
                                        ->get()
                                        ->mapWithKeys(fn (Plan $plan): array => [$plan->id => self::planOptionLabel($plan)])
                                        ->all())
                                    ->default($record->plan_id)
                                    ->required()
                                    ->live()
                                    ->native(false)
                                    ->columnSpanFull(),

                                Radio::make('apply_mode')
                                    ->label(__('Apply mode'))
                                    ->options([
                                        PlanTransitionMode::EndOfPeriod->value => __('End of period'),
                                        PlanTransitionMode::Immediate->value => __('Immediate'),
                                        PlanTransitionMode::AtDate->value => __('At a specific date'),
                                    ])
                                    ->default(PlanTransitionMode::EndOfPeriod->value)
                                    ->required()
                                    ->live()
                                    ->columnSpanFull(),

                                DatePicker::make('scheduled_at')
                                    ->label(__('Scheduled date'))
                                    ->native(false)
                                    ->minDate(now()->addDay())
                                    ->required(fn (Get $get): bool => $get('apply_mode') === PlanTransitionMode::AtDate->value)
                                    ->visible(fn (Get $get): bool => $get('apply_mode') === PlanTransitionMode::AtDate->value)
                                    ->columnSpanFull(),

                                Group::make()
                                    ->columnSpanFull()
                                    ->columns(2)
                                    ->schema(fn (Get $get): array => self::changePlanQuantityFields($get('target_plan_id'), $record)),
                            ]),
                    ])
                    ->action(function (array $data, License $record): void {
                        $newPlan = Plan::query()->findOrFail($data['target_plan_id']);
                        $mode = PlanTransitionMode::from($data['apply_mode']);

                        $overrides = collect($data['quantity_overrides'] ?? [])
                            ->mapWithKeys(fn ($q, $id): array => [(int) $id => (int) $q])
                            ->all();

                        $scheduledAt = ! empty($data['scheduled_at'])
                            ? CarbonImmutable::parse($data['scheduled_at'])
                            : null;

                        try {
                            Entitlements::changePlan($record, $newPlan, $mode, $overrides, $scheduledAt);
                        } catch (
                            PlanCategoryExclusivityViolation
                            |IncompatiblePlanTransition
                            |InsufficientCapacityForTransition
                            |AnchorNotActiveForTransition
                            |NoOpPlanTransition
                            |InvalidTransitionScheduledDate $e
                        ) {
                            self::notifyDomainError($e);

                            return;
                        }

                        Notification::make()
                            ->title(__('Change plan'))
                            ->success()
                            ->send();
                    }),

                Action::make('cancelTransition')
                    ->label(__('Cancel pending change'))
                    ->icon('heroicon-o-x-circle')
                    ->iconButton()
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (License $record): bool => $record->parent_id === null && $record->pendingTransition() !== null)
                    ->action(function (License $record): void {
                        $pending = $record->pendingTransition();

                        if ($pending === null) {
                            return;
                        }

                        try {
                            Entitlements::cancelTransition($pending);
                        } catch (TransitionAlreadyResolved $e) {
                            self::notifyDomainError($e);

                            return;
                        }

                        Notification::make()
                            ->title(__('Cancel pending change'))
                            ->success()
                            ->send();
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

    /**
     * Build a numeric input per flexible item of the targeted plan for plan changes.
     * Prefills each field with the current slot_total of the anchor's group for the same type,
     * falling back to the plan item's default quantity when no matching license exists.
     *
     * @return array<int, TextInput>
     */
    private static function changePlanQuantityFields(mixed $planId, License $anchor): array
    {
        if (empty($planId)) {
            return [];
        }

        $plan = Plan::query()->with('items')->find($planId);

        if ($plan === null) {
            return [];
        }

        $currentByType = License::query()
            ->where(fn ($q) => $q->where('id', $anchor->id)->orWhere('parent_id', $anchor->id))
            ->get()
            ->groupBy(fn (License $l) => $l->type->value)
            ->map(fn ($licenses) => (int) $licenses->sum('slot_total'));

        return $plan->items
            ->where('is_flexible', true)
            ->map(fn (PlanItem $item): TextInput => TextInput::make("quantity_overrides.{$item->id}")
                ->label(trans(':type quantity', ['type' => self::typeLabel($item->type)]))
                ->numeric()
                ->minValue(1)
                ->required()
                ->default($currentByType[$item->type->value] ?? $item->quantity))
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

    private static function notifyDomainError(\Throwable $e): void
    {
        Notification::make()
            ->title(__('Operation not permitted'))
            ->body($e->getMessage())
            ->danger()
            ->send();
    }
}
