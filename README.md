# Simple and flexible entitlement management for Laravel applications, with support for plans, features, limits, and usage tracking.

## Filament integration (optional)

The package ships a Filament v5 plugin that exposes a Plans/Plan Categories admin UI and a `LicensesRelationManager` you can attach to your subscriber resource (e.g. `Workspace`, `Team`, `User`).

Install Filament v5 plus the two optional UI dependencies the resources rely on:

```bash
composer require filament/filament:^5.0 awcodes/badgeable-column codewithdennis/filament-lucide-icons
```

Register the plugin on your panel:

```php
use LucaLongo\LaravelEntitlements\Filament\EntitlementsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(EntitlementsPlugin::make());
}
```

Attach the `LicensesRelationManager` to the resource of your subscriber model:

```php
use LucaLongo\LaravelEntitlements\Filament\RelationManagers\LicensesRelationManager;

public static function getRelations(): array
{
    return [LicensesRelationManager::class];
}
```
