<?php

declare(strict_types=1);

use LucaLongo\LaravelEntitlements\Support\EntitlementTypeLabel;
use Workbench\App\Enums\TestType;

it('returns empty string for null', function (): void {
    expect(EntitlementTypeLabel::resolve(null))->toBe('');
});

it('translates the enum case name when no getLabel method exists', function (): void {
    app('translator')->addLines(['*.Single' => 'Singola'], 'en');

    expect(EntitlementTypeLabel::resolve(TestType::Single))->toBe('Singola');
});

it('falls back to the case name when no translation is registered', function (): void {
    expect(EntitlementTypeLabel::resolve(TestType::Pooled))->toBe('Pooled');
});
