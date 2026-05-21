<?php

declare(strict_types=1);

it('ships JSON translation files for every supported locale', function (string $locale): void {
    $path = __DIR__.'/../../lang/'.$locale.'.json';

    expect(file_exists($path))->toBeTrue();

    $translations = json_decode((string) file_get_contents($path), true);

    expect($translations)->toBeArray()->not->toBeEmpty();
})->with(['en', 'it', 'zh', 'ru']);

it('keeps every locale aligned to the English keyset', function (): void {
    $english = array_keys(json_decode((string) file_get_contents(__DIR__.'/../../lang/en.json'), true));

    foreach (['it', 'zh', 'ru'] as $locale) {
        $keys = array_keys(json_decode((string) file_get_contents(__DIR__.'/../../lang/'.$locale.'.json'), true));

        expect($keys)->toEqualCanonicalizing($english);
    }
});
