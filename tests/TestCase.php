<?php

declare(strict_types=1);

namespace LucaLongo\LaravelEntitlements\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\File;
use LucaLongo\LaravelEntitlements\LaravelEntitlementsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Enums\TestType;

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
        return [
            LaravelEntitlementsServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
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

        foreach (File::allFiles(__DIR__.'/../database/migrations') as $migration) {
            (include $migration->getRealPath())->up();
        }
    }
}
