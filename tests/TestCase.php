<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use CodeWithDennis\FilamentLucideIcons\FilamentLucideIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\File;
use Livewire\LivewireServiceProvider;
use LucaLongo\LaravelEntitlements\LaravelEntitlementsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;
use Technikermathe\LucideIcons\BladeLucideIconsServiceProvider;
use Workbench\App\Enums\TestType;
use Workbench\App\Providers\Filament\AdminPanelProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'LucaLongo\\LaravelEntitlements\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        $optional = [
            FilamentServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeLucideIconsServiceProvider::class,
            FilamentLucideIconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            AdminPanelProvider::class,
        ];

        $providers = array_values(array_filter($optional, fn (string $class): bool => class_exists($class)));
        $providers[] = LaravelEntitlementsServiceProvider::class;

        return $providers;
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config()->set('entitlements.type_enum', TestType::class);

        foreach (File::allFiles(__DIR__.'/../workbench/database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
        }

        $migrations = collect(File::allFiles(__DIR__.'/../database/migrations'))
            ->sortBy(fn ($m) => (str_starts_with($m->getFilename(), 'create_') ? '0_' : '1_').$m->getFilename())
            ->values();

        foreach ($migrations as $migration) {
            (include $migration->getRealPath())->up();
        }
    }
}
