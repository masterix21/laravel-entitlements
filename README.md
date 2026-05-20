# Simple and flexible entitlement management for Laravel applications, with support for plans, features, limits, and usage tracking.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/masterix21/laravel-entitlements.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-entitlements)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/masterix21/laravel-entitlements/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/masterix21/laravel-entitlements/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/masterix21/laravel-entitlements/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/masterix21/laravel-entitlements/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/masterix21/laravel-entitlements.svg?style=flat-square)](https://packagist.org/packages/masterix21/laravel-entitlements)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/laravel-entitlements.jpg?t=1" width="419px" />](https://spatie.be/github-ad-click/laravel-entitlements)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Installation

You can install the package via composer:

```bash
composer require masterix21/laravel-entitlements
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laravel-entitlements-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-entitlements-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="laravel-entitlements-views"
```

## Usage

Define a backed enum in your application that implements `EntitlementType`:

```php
use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Strategies\PoolStrategy;
use LucaLongo\LaravelEntitlements\Strategies\SlotStrategy;

enum LicenseType: string implements EntitlementType
{
    case Device = 'device';
    case AiTokens = 'ai_tokens';

    public function strategy(): EntitlementStrategy
    {
        return match ($this) {
            self::Device => new SlotStrategy(twoPhase: true),
            self::AiTokens => new PoolStrategy(),
        };
    }
}
```

Then set it in `config/entitlements.php`:

```php
'type_enum' => \App\Enums\LicenseType::class,
```

Add the trait to the model that owns licenses:

```php
use LucaLongo\LaravelEntitlements\Concerns\HasEntitlements;

class Workspace extends Model
{
    use HasEntitlements;
}
```

Use the facade:

```php
use LucaLongo\LaravelEntitlements\Facades\Entitlements;

Entitlements::assignPlan($workspace, $plan, now());
Entitlements::consume($workspace, LicenseType::Device, $device);
Entitlements::requestRelease($usage);
Entitlements::confirmRelease($usage);
Entitlements::consume($workspace, LicenseType::AiTokens, $aiUsage, amount: 1500);
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Luca Longo](https://github.com/masterix21)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
