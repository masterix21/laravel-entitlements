<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use LucaLongo\LaravelEntitlements\LaravelEntitlementsServiceProvider;

function entitlementsPublishPaths(string $tag): array
{
    return ServiceProvider::pathsToPublish(LaravelEntitlementsServiceProvider::class, $tag);
}

it('publishes the config under the entitlements-config tag', function (): void {
    $paths = entitlementsPublishPaths('entitlements-config');

    expect($paths)->toHaveCount(1);

    $source = array_key_first($paths);

    expect($source)->toEndWith('config'.DIRECTORY_SEPARATOR.'entitlements.php')
        ->and($paths[$source])->toBe(config_path('entitlements.php'));
});

it('publishes every migration stub under the entitlements-migrations tag', function (): void {
    $paths = entitlementsPublishPaths('entitlements-migrations');

    $expected = collect(glob(__DIR__.'/../../database/migrations/*.php.stub'))->count();

    expect($paths)->toHaveCount($expected)
        ->and($expected)->toBeGreaterThan(0);

    foreach ($paths as $source => $target) {
        expect($source)->toEndWith('.php.stub')
            ->and($target)->toMatch('/\d{4}_\d{2}_\d{2}_\d{6}_.+\.php$/');
    }
});

it('publishes translations under the entitlements-translations tag', function (): void {
    $paths = entitlementsPublishPaths('entitlements-translations');

    expect($paths)->toHaveCount(1);

    $source = array_key_first($paths);

    expect($source)->toEndWith('lang')
        ->and($paths[$source])->toBe($this->app->langPath());
});

it('does not register the legacy laravel-entitlements-* publish tags', function (): void {
    expect(ServiceProvider::publishableGroups())
        ->not->toContain('laravel-entitlements-config')
        ->not->toContain('laravel-entitlements-migrations')
        ->not->toContain('laravel-entitlements-translations');
});
