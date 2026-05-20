# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Package overview

`masterix21/laravel-entitlements` — a Laravel package for entitlement management: plans, features, limits, and usage tracking. Built on the Spatie package skeleton (`spatie/laravel-package-tools`). Requires PHP ^8.4 and Laravel 11/12/13.

The package is in early scaffolding: the Spatie skeleton has been renamed to `LaravelEntitlements` but the domain (plans/features/limits/usage) is not yet implemented. New code should establish those concepts in `src/`.

## Commands

- `composer test` — run the Pest test suite (alias for `vendor/bin/pest`).
- `vendor/bin/pest --filter='test name'` — run a single test.
- `composer test-coverage` — Pest with coverage.
- `composer analyse` — PHPStan (larastan) static analysis; baseline at `phpstan-baseline.neon`, config `phpstan.neon.dist`.
- `composer format` — Laravel Pint code style fixer.
- `composer prepare` — runs Testbench's `package:discover` (runs automatically post-autoload-dump).

## Architecture

- **Service provider**: `src/LaravelEntitlementsServiceProvider.php` configures the package via Spatie's fluent `Package` API (config file, views, migration `create_laravel_entitlements_table`, console command). Add new migrations/commands/views here using `hasMigration()`, `hasCommand()`, etc.
- **Namespace**: `LucaLongo\LaravelEntitlements\` → `src/`; factories at `LucaLongo\LaravelEntitlements\Database\Factories\` → `database/factories/`.
- **Migrations**: stub files in `database/migrations/` ending in `.php.stub` — published to the host app via `vendor:publish --tag="laravel-entitlements-migrations"`. Do NOT include `down()` methods in migrations (per user guidelines).
- **Config**: `config/entitlements.php` published with tag `laravel-entitlements-config`.
- **Tests**: Pest 4 + Orchestra Testbench 9/10/11. `tests/TestCase.php` wires the service provider and factory namespace resolver. `tests/Pest.php` configures the base test case. The migration auto-load block in `getEnvironmentSetUp` is commented out — uncomment when DB-backed tests are added.
- **Workbench**: dev-only app at `workbench/app/` (namespace `Workbench\App\`) for Testbench-based integration scenarios.

## Conventions specific to this repo

- Migrations have no `down()` method.
- The package targets PHP 8.4 — use modern features (asymmetric visibility, property hooks where useful, typed properties, constructor property promotion).
- Follow Spatie Laravel/PHP guidelines (already configured via Pint + Larastan).
