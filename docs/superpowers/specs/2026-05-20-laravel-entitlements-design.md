# laravel-entitlements — Design Spec

**Date:** 2026-05-20
**Package:** `masterix21/laravel-entitlements`
**Source of business logic:** `~/Dev/Sites/totem-in-cloud` (Plan / PlanCategory / PlanItem / License / LicenseUsage / LicenseService)

## 1. Goal

Estrarre dal progetto `totem-in-cloud` il sistema di piani di abbonamento e licenze come package riusabile per Laravel 11/12/13, PHP ^8.4. La parte project-specific (l'enum dei tipi di entitlement, es. `Device`, `AiTokens`) diventa configurabile per progetto tramite un'interface PHP più un riferimento in `config/entitlements.php`.

## 2. Scope

### In scope
- Catalogo: `PlanCategory`, `Plan`, `PlanItem`
- Istanze: `License` (per owner polimorfico), `LicenseUsage` (per subject polimorfico)
- Strategy di consumo: slot esclusivo (1 usage per subject, two-phase release opzionale) e pool drainabile (drain FIFO su più licenze valide)
- Service facade `Entitlements` con API generica
- Eventi e eccezioni per integrazione lato app
- Migration pubblicabili, config pubblicabile, factories per testbench

### Out of scope (resta nell'app integratrice)
- DeviceCommand, DeviceStatus, intero flusso device di totem
- Quota orarie AI (`canUseAi`) e altre policy di dominio
- Filament resources / forms
- Fatturazione, Stripe, gateways

## 3. Architectural decisions

| Decisione | Scelta |
|-----------|--------|
| Owner del License | MorphTo polimorfico (`subscriber_type` + `subscriber_id`) + trait `HasEntitlements` |
| Strategie per-tipo | Strategy class (`SlotStrategy`, `PoolStrategy`) mappate via enum dell'app |
| Definizione enum dei tipi | Backed enum nell'app che implementa `EntitlementType` (con metodo `strategy()`); riferimento in `config('entitlements.type_enum')` |
| Naming tabelle | Prefisso `entitlement_` |
| Traduzioni `name` | `spatie/laravel-translatable` come require |
| Filament `HasLabel` sugli enum del package | NO, framework-agnostic |
| Two-phase release | Opt-in per strategy (default off; `SlotStrategy` configurabile, `PoolStrategy` sempre diretta) |
| Migration `down()` | Mai presente (convention di repo) |
| Logica "anchor" parent/child di totem | Rimossa — caso specifico totem. `parent_id` resta nello schema per future estensioni |

## 4. Schema

Tutte le tabelle hanno il prefisso `entitlement_`.

```
entitlement_plan_categories
  id, name (JSON, translatable), sort (int default 0), timestamps

entitlement_plans
  id,
  plan_category_id (nullable, FK -> entitlement_plan_categories, null on delete),
  name (JSON, translatable),
  billing_period (string, cast BillingPeriod),
  is_recurring (bool default false),
  is_active (bool default true),
  timestamps

entitlement_plan_items
  id,
  plan_id (FK -> entitlement_plans, cascade on delete),
  type (string — il value dell'enum dell'app),
  quantity (unsigned int),
  is_flexible (bool default false),
  timestamps

entitlement_licenses
  id,
  subscriber_type (string),
  subscriber_id (unsigned bigint),
  plan_id (FK -> entitlement_plans),
  parent_id (nullable, FK self, null on delete),
  type (string),
  slot_total (unsigned int),
  slot_used (unsigned int default 0),
  last_checked_at (timestamp nullable),
  starts_at (timestamp),
  ends_at (timestamp nullable),
  timestamps
  INDEX (subscriber_type, subscriber_id, type)
  INDEX (starts_at, ends_at)

entitlement_license_usages
  id,
  license_id (FK -> entitlement_licenses, cascade on delete),
  subject_type (string),
  subject_id (unsigned bigint),
  amount (unsigned int),
  status (string default 'active'),
  timestamps
  INDEX (license_id, status)
```

Le migration sono pubblicate come `.php.stub` con il tag `entitlements-migrations`. Nessun metodo `down()`.

## 5. Namespace e classi

Namespace base: `LucaLongo\LaravelEntitlements\`.

### Models (`src/Models/`)
Tutti `final`, swappabili via `config('entitlements.models.*')`.

- **`PlanCategory`** — `HasTranslations(['name'])`, `hasMany(Plan)`
- **`Plan`** — `HasTranslations(['name'])`, `belongsTo(PlanCategory)`, `hasMany(PlanItem)`, scope `active()`, cast `billing_period => BillingPeriod`
- **`PlanItem`** — `belongsTo(Plan)`, cast `type` all'enum risolto da `config('entitlements.type_enum')`
- **`License`** — `morphTo('subscriber')`, `belongsTo(Plan)`, self `parent`/`children`, `hasMany(LicenseUsage)`, attribute `remaining` (= `max(0, slot_total - slot_used)`), metodo `isValid()`, scope `valid()` (starts_at ≤ now AND (ends_at IS NULL OR ends_at > now)), scope `ofType(EntitlementType $type)`
- **`LicenseUsage`** — `belongsTo(License)`, `morphTo('subject')`, scope `open()` (status ≠ Released), cast `status => LicenseUsageStatus`

### Enums (`src/Enums/`)
- **`BillingPeriod`** — `Monthly`, `Yearly`; metodo `advance(CarbonInterface): CarbonInterface`. Niente `HasLabel`.
- **`LicenseUsageStatus`** — `Active`, `Releasing`, `Released`. Niente `HasLabel`.

### Contracts (`src/Contracts/`)
```php
interface EntitlementType extends \BackedEnum
{
    public function strategy(): EntitlementStrategy;
}

interface EntitlementStrategy
{
    public function consume(License $license, Model $subject, int $amount = 1): LicenseUsage;
    public function requestRelease(LicenseUsage $usage): void;
    public function confirmRelease(LicenseUsage $usage): void;
    public function forceRelease(LicenseUsage $usage): void;
    public function supportsTwoPhaseRelease(): bool;
}
```

### Strategies (`src/Strategies/`)
- **`SlotStrategy`** — costruita con `twoPhase: bool = false`. `consume()` crea un singolo `LicenseUsage` con `amount=1` sulla license corrente (atomic, `lockForUpdate`), incrementa `slot_used`. Con `twoPhase=true`: `requestRelease` mette status `Releasing`, `confirmRelease` mette `Released` e decrementa `slot_used`; senza two-phase: `requestRelease` ≡ `confirmRelease` (release diretto).
- **`PoolStrategy`** — `consume()` con `amount=N` distribuisce `N` su una o più licenze valide dell'owner (ordinate `ends_at IS NULL, ends_at ASC`, `lockForUpdate`), creando un usage per ciascuna. Release sempre diretto. `supportsTwoPhaseRelease()` ritorna `false`.

### Service & Facade (`src/Entitlements.php`, `src/Facades/Entitlements.php`)

```php
Entitlements::assignPlan(Model $subscriber, Plan $plan, CarbonInterface $startsAt, array $quantityOverrides = []): Collection<License>
Entitlements::consume(Model $subscriber, EntitlementType $type, Model $subject, int $amount = 1): LicenseUsage
Entitlements::requestRelease(LicenseUsage $usage): void
Entitlements::confirmRelease(LicenseUsage $usage): void
Entitlements::forceRelease(LicenseUsage $usage): void
Entitlements::available(Model $subscriber, EntitlementType $type): int
Entitlements::capacity(Model $subscriber, EntitlementType $type): int
Entitlements::can(Model $subscriber, EntitlementType $type, int $amount = 1): bool
Entitlements::reconcile(License $license): void
Entitlements::recalculate(Model $subscriber): array  // {reconciled: int}
```

`assignPlan` itera su `$plan->items`, per ognuno crea una `License` (`slot_total = override se item is_flexible e override presente, altrimenti item.quantity`), calcola `ends_at = is_recurring ? null : billing_period->advance(starts_at)`. Tutto in `DB::transaction`.

`consume/requestRelease/confirmRelease/forceRelease` risolvono la strategy via `$type->strategy()` e delegano.

### Trait (`src/Concerns/HasEntitlements.php`)
Aggiunge al model owner:
```php
public function licenses(): MorphMany  // morphMany(License, 'subscriber')
```

### Events (`src/Events/`)
- `PlanAssigned(Model $subscriber, Plan $plan, Collection $licenses)`
- `LicenseConsumed(LicenseUsage $usage)`
- `ReleaseRequested(LicenseUsage $usage)`
- `LicenseReleased(LicenseUsage $usage)`
- `LicenseReconciled(License $license)`

### Exceptions (`src/Exceptions/`)
- `NoEntitlementAvailableException` — sollevata da strategy quando capacity insufficiente
- `InvalidEntitlementTypeException` — sollevata se `config('entitlements.type_enum')` non implementa `EntitlementType`

## 6. Service provider

`LaravelEntitlementsServiceProvider` (estende Spatie `PackageServiceProvider`):

```php
$package
    ->name('laravel-entitlements')
    ->hasConfigFile('entitlements')
    ->hasMigrations([
        'create_entitlement_plan_categories_table',
        'create_entitlement_plans_table',
        'create_entitlement_plan_items_table',
        'create_entitlement_licenses_table',
        'create_entitlement_license_usages_table',
    ]);
```

In `registeringPackage()` o `bootingPackage()`:
- Binda il service `Entitlements` come singleton
- Valida che `config('entitlements.type_enum')` implementi `EntitlementType` (lazy, alla prima risoluzione)

## 7. Config (`config/entitlements.php`)

```php
return [
    // Enum dell'app che implementa LucaLongo\LaravelEntitlements\Contracts\EntitlementType
    'type_enum' => null, // es. \App\Enums\LicenseType::class

    'models' => [
        'plan_category' => \LucaLongo\LaravelEntitlements\Models\PlanCategory::class,
        'plan' => \LucaLongo\LaravelEntitlements\Models\Plan::class,
        'plan_item' => \LucaLongo\LaravelEntitlements\Models\PlanItem::class,
        'license' => \LucaLongo\LaravelEntitlements\Models\License::class,
        'license_usage' => \LucaLongo\LaravelEntitlements\Models\LicenseUsage::class,
    ],

    'table_names' => [
        'plan_categories' => 'entitlement_plan_categories',
        'plans' => 'entitlement_plans',
        'plan_items' => 'entitlement_plan_items',
        'licenses' => 'entitlement_licenses',
        'license_usages' => 'entitlement_license_usages',
    ],
];
```

## 8. Test plan (Pest + Orchestra Testbench)

### Workbench fixtures
- `Workbench\App\Models\Subscriber` (model con `HasEntitlements`)
- `Workbench\App\Models\Subject` (model usato come morph subject)
- `Workbench\App\Enums\TestType implements EntitlementType` con due case: `Single` → `SlotStrategy(twoPhase: true)`, `Pooled` → `PoolStrategy()`
- Migration di test in `workbench/database/migrations/` per `subscribers` e `subjects`
- In `tests/TestCase.php` uncommentare il caricamento delle migration del package

### Test files (Pest, `tests/Feature/`)
1. **`PlanAssignmentTest.php`** — `assignPlan` crea N licenze, calcola `ends_at` correttamente per `is_recurring` true/false, applica `quantityOverrides` solo a item con `is_flexible=true`
2. **`SlotStrategyTest.php`** — single-phase: `consume` crea usage e incrementa `slot_used`; capacità esaurita → `NoEntitlementAvailableException`. Two-phase: `requestRelease` → status `Releasing` senza decrement; `confirmRelease` → `Released` con decrement; `forceRelease` da `Active` o `Releasing`
3. **`PoolStrategyTest.php`** — `consume` su pool sufficiente da una licenza; drain su più licenze ordinate per `ends_at`; richiesta che eccede il pool → exception; usa `lockForUpdate` (test concorrenza opzionale)
4. **`ScopesTest.php`** — `valid()` filtra per now; `ofType()` filtra per enum; `open()` su usages
5. **`ReconciliationTest.php`** — `reconcile(License)` ricalcola `slot_used` dalle usages aperte; `recalculate(Subscriber)` itera su tutte le licenze
6. **`EventsTest.php`** — verifica dispatch di `PlanAssigned`, `LicenseConsumed`, `ReleaseRequested`, `LicenseReleased`, `LicenseReconciled`
7. **`ConfigValidationTest.php`** — type_enum non impostato o non implementa il contract → `InvalidEntitlementTypeException`

Coverage attesa: ≥ 90% sui service/strategy/models.

## 9. Dipendenze composer

```json
"require": {
    "php": "^8.4",
    "illuminate/contracts": "^11.0|^12.0|^13.0",
    "spatie/laravel-package-tools": "^1.16",
    "spatie/laravel-translatable": "^6.0"
}
```

Già presenti `pestphp/pest`, `orchestra/testbench`, `larastan/larastan`, `laravel/pint`.

## 10. Milestone di implementazione (alto livello)

Verranno dettagliate dal `writing-plans` skill. Indicativamente:

1. Schema & migration stubs
2. Enum + contract + exception
3. Models + trait `HasEntitlements`
4. Strategies (Slot + Pool)
5. Service `Entitlements` + facade + service provider binding
6. Events
7. Workbench fixtures + test suite
8. README usage example + composer.json finalization

## 11. Esempio d'uso (post-installazione, lato app)

```php
// app/Enums/LicenseType.php
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

// config/entitlements.php
'type_enum' => \App\Enums\LicenseType::class,

// app/Models/Workspace.php
class Workspace extends Model
{
    use HasEntitlements;
}

// Utilizzo
Entitlements::assignPlan($workspace, $plan, now());
Entitlements::consume($workspace, LicenseType::Device, $device);
Entitlements::requestRelease($usage); // -> evento ReleaseRequested, app invia DeviceCommand
Entitlements::confirmRelease($usage); // chiamato quando device conferma
Entitlements::consume($workspace, LicenseType::AiTokens, $aiUsage, amount: 1500);
```
