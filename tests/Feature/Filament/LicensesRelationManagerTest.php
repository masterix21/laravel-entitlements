<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Filament\RelationManagers\LicensesRelationManager;

it('exposes the change plan action and no longer exposes the legacy edit action', function (): void {
    $reflection = new ReflectionClass(LicensesRelationManager::class);
    $source = (string) file_get_contents((string) $reflection->getFileName());

    expect($source)->toContain("Action::make('changePlan')")
        ->and($source)->toContain("Action::make('cancelTransition')")
        ->and($source)->not->toContain('EditAction::make()')
        ->and($source)->not->toContain("Action::make('editPlan')");
});

it('keeps the pending plan change translation key in every locale', function (): void {
    foreach (['en', 'it', 'zh', 'ru'] as $locale) {
        $translations = json_decode((string) file_get_contents(__DIR__.'/../../../lang/'.$locale.'.json'), true);

        expect($translations)->toHaveKey('Change plan')
            ->and($translations)->toHaveKey('Cancel pending change')
            ->and($translations)->toHaveKey('Pending plan change')
            ->and($translations)->toHaveKey('Apply mode')
            ->and($translations)->toHaveKey('End of period')
            ->and($translations)->toHaveKey('Immediate');
    }
});
