# Static analysis support for packages

## Status and purpose

Not started.

Packages that analyze themselves, and applications that analyze code using packages, lose schema facts in four places. This plan fixes those places and documents how packages ship PHPStan support:

- **Blueprint macros** make their table unknown. Only the hardcoded nested set and Scout `searchable` macros are read.
- **Migration connections.** A `getConnection()` inherited from a base class or trait makes every connection's schema unknown. So does a getter that asks a service.
- **`shouldRun()`.** The reader applies migrations that `shouldRun()` skips.
- **Testbench migrations.** Package analysis ignores the migrations a package's Testbench setup lists.

Distribution needs documentation only. PHPStan's extension installer already loads `extra.phpstan.includes` from any installed package, whatever its Composer `type`. It never loads the root package's own entry. hypervel/phpstan's `extension.neon` parameters merge as maps: framework defaults, then each package, then the application, where a later entry replaces one with the same key.

## Owner approval

All of the following are additive. No Laravel API or runtime behavior changes, and `Migrator` is untouched.

- A public extension point: the `BlueprintMacroExtension` interface, the `BlueprintMacroCall` class, and the `hypervel.blueprintMacroExtension` tag.
- A `migrationConnections` parameter.
- Analysis honors `shouldRun()`.
- Package analysis reads Testbench's migration paths.

## Design

### 1. Blueprint macro descriptions

A package describes what its Blueprint macros do to columns. It registers a class through PHPStan's own extension mechanism.

```php
namespace Hypervel\PHPStan\Schema;

use PHPStan\DependencyInjection\ExtensionInterface;

#[ExtensionInterface('hypervel.blueprintMacroExtension')]
interface BlueprintMacroExtension
{
    /**
     * Get the described macros, with the class whose code registers each.
     *
     * @return array<string, class-string>
     */
    public function getMacros(): array;

    /**
     * Describe one call of a macro.
     */
    public function apply(string $macro, BlueprintMacroCall $call): void;
}
```

`BlueprintMacroCall` is a final class with an `@internal` constructor. Its methods use Blueprint's own vocabulary:

| Method | Meaning |
|---|---|
| `name(int $position, string $parameter, ?string $default = null): ?string` | A string argument, by position or name. Returns `null` when the argument is not a literal or class constant (`getNameArgument()`). |
| `creating(): bool` | Whether the call runs inside `Schema::create()`. |
| `hasColumn(string $name): ?bool` | Whether the table has the column, matched through `resolveColumnName()`. Returns `null` when the table is incomplete and the column is not known. Reports the table as the call found it. |
| `addColumn(string $type, ?string $name, bool $nullable = false, bool $unsigned = false): void` | Adds a column. `$type` is a Blueprint type such as `string`, `bigInteger` or `uuid`, lowercased for the reader's type tables. A `null` name adds an unnamed column, which keeps the known columns. |
| `dropColumn(?string $name): void` | Drops a column. A `null` name, or one `resolveColumnName()` can't resolve, makes the table unknown. |

Effects are recorded, then applied in order once `apply()` returns, through the reader's existing helpers:
- `setColumn()` and `addUnnamedColumn($table, false)` for adds;
- `resolveColumnName()` and `SchemaTable::dropColumn()` for drops.

Strings are the only argument type, because no describer needs another. There is no "unknown" method: an undescribable call means the macro isn't described, which keeps today's behavior of forgetting the table.

**Ownership.** A description applies only while `Blueprint::$macros[$name]` is a Closure whose scope class equals the declared class. This generalizes today's `getKnownMacros()`. A macro that the application replaces makes the table unknown.

Macro names are case-sensitive, as in `Macroable::hasMacro()`.

**Composition.** Every tagged extension contributes. If two extensions describe the same macro for the same live class, analysis throws a `LogicException` at startup that names both.

**Chained calls after a described macro** follow the live closure's native return type:
- `ForeignKeyDefinition`, `IndexDefinition` or `void`: chains are ignored, as for `foreign()` and `index()`.
- Any other type: a chained `nullable`, `unsigned`, `type` or `change` after a call that added columns makes the table unknown. These are the modifiers the reader already honors. The Fluent mutators already forget the table for every call.

**Built-in descriptions.** `#[AutowiredService]` classes in `src/phpstan/src/Schema/` replace the hardcoded cases with identical facts:
- `NestedSetBlueprintMacros`, keyed to `NestedSetServiceProvider`:
  - `nestedSet`, `integerNestedSet`, `uuidNestedSet` and `ulidNestedSet` add `integer` `LFT` and `RGT` and `smallInteger` `DEPTH`, all unsigned. They add a nullable `PARENT_ID` of type `bigInteger` (unsigned), `integer` (unsigned), `uuid` or `char`.
  - `dropNestedSet` drops each of `NestedSet::getDefaultColumns()`.
- `SearchableBlueprintMacros`, keyed to `SearchableSchema`:
  - `searchable` adds `tsvector` named by `name(3, 'tsvector', 'search_vector')`.
  - `dropSearchable` drops `name(1, 'tsvector', 'search_vector')`.

**Example for the docs and test fixtures** (a fictional `acme/billing` package):

```php
class BillingBlueprintMacros implements BlueprintMacroExtension
{
    public function getMacros(): array
    {
        return array_fill_keys(['invoiceReference', 'billingCustomer', 'currencyAmount'], BillingSchema::class);
    }

    public function apply(string $macro, BlueprintMacroCall $call): void
    {
        if ($macro === 'invoiceReference') {
            $call->addColumn('string', 'invoice_reference');
        } elseif ($macro === 'billingCustomer') {
            // Rows that already exist have no customer.
            $call->addColumn('uuid', 'billing_customer_id', nullable: ! $call->creating());
        } else {
            // Amounts share the table's currency column.
            $call->addColumn('decimal', $call->name(0, 'column'));

            if ($call->hasColumn('currency') === false) {
                $call->addColumn('char', 'currency');
            }
        }
    }
}
```

`BillingSchema::register()` defines the matching macros:
- `invoiceReference()` returns the `ColumnDefinition`;
- `billingCustomer()` returns the `ForeignKeyDefinition` to `billing_customers`;
- `currencyAmount(string $column)` returns the amount's `ColumnDefinition`.

**Rejected alternatives.**
- **Reading macro closure bodies.** It needs source location, argument substitution and return-value tracking, and it still loses common macros that loop or read the blueprint's state.
- **Declaring effects in NEON.** Real effects depend on arguments and conditions, so it would grow into an expression language.

### 2. Migration connections

`getMigrationConnection()` finds the migration's effective `getConnection()` with `findMethod()`: its own, a trait's, or a parent's.

| Result | Connection |
|---|---|
| `null` (Migration's own) | The `$connection` property, as today (`evaluateConnectionProperty()`, write check included). |
| Declared by a class listed in `migrationConnections` | That entry's resolved value. |
| Any other declaration | Today's single-`return` evaluation, now also for inherited and trait methods. The string-return-type rule is kept. |
| `false` | Unknown. |

The migration's parents and traits are already sources (`addClassStatements()`), so cache invalidation needs nothing new. `findDeclaredMethod()` reports the class that uses a trait as the declaring class, so a base class that gets `getConnection()` from a trait still matches its map entry.

**`migrationConnections` parameter.** Default `[]`; schema `arrayOf(string(), string())`, the same shape as `eloquentBuilderMacros`. It maps a base migration class that declares `getConnection()` to a Laravel-style `Class@method` callback returning the connection:

```neon
parameters:
    migrationConnections:
        Acme\Billing\Database\BillingMigration: Acme\Billing\BillingConfiguration@connection
```

A string value replaces normally: a package entry and an application entry with the same key leave the application's, and distinct keys merge. A list value would concatenate instead.

- **Ownership:** `ConnectionConfigResolver` owns the parameter, which joins its `getSchemaConfiguration()`.
- **Resolution:**
  - Resolve lazily, only when a migration's effective getter is declared by a mapped class, and memoize per class.
  - `Str::parseCallback()` splits the value. A value without `@method` throws an `InvalidArgumentException` naming the key.
  - The call is `$this->getContainer()->make($class)->{$method}()`, so container rebindings apply.
  - A string or `null` result is known; anything else is unknown.
  - Mappings no selected migration uses never instantiate their service.
- **Cache:** each resolved result is recorded as `resolvedConnections`, like `configuredNames`. `MigrationCache` stores the record, and on a warm run resolves only the recorded classes and compares them.
- **Sources:** the service's class files (`SourceFiles::forClass()`) join the aggregator's sources when an entry decides a connection.
- **Migration files** are parsed, never loaded.
- **Overrides:** an override below the mapped class is read normally.
- **Unlisted getters:** unlisted getters the reader can't evaluate keep today's behavior. They are unknown and never run.

**Rejected alternatives.**
- **Resolving migrations and calling `getConnection()`.** `Migrator::resolvePath()` runs top-level PHP and constructors. Making that protected Laravel method public breaks subclasses that redeclare it as protected. And the guide promises PHPStan doesn't execute migrations.
- **Calling service chains found in getter source.** That is implicit execution.

### 3. `shouldRun()`

`addClassStatements()` finds `shouldRun()` with `findMethod()` before applying `up()`:

| Result | Action |
|---|---|
| `null` (Migration's own, which returns `true`) | Apply. |
| A single `return` of `true` | Apply. |
| A single `return` of `false` | Skip. |
| A single `return` of a config read whose value or literal default is a boolean | Apply or skip. |
| Anything else, including `false` from `findMethod()` | Take a `snapshot()`, apply, then `restore($this->mergeStates(...))` with the snapshot, as `applyBody()` merges an early return. |

- **Config reads:**
  - the shapes are `config('key', default)`, `config()->get|boolean('key', default)` and `Config::get|boolean('key', default)`, with a literal key;
  - a present key that isn't a boolean, or an absent key without a literal boolean default, is unknown;
  - nothing is called (no Pennant or other guards).
- **Shared helper.** Extract the config-read recognition from `evaluateConnectionName()` into one helper that returns the read method, key and default. It has two callers.
- **Cache.**
  - `ConnectionConfigResolver::getConfiguredName()` turns booleans into its `false` "not a name" marker, so it can't record them.
  - Add `getConfiguredBoolean(string $key): array{bool, ?bool}` (present, and the value when it is a boolean), recorded in `$configuredBooleans` with a `getConfiguredBooleans()` accessor.
  - `MigrationCache` stores and compares `configuredBooleans` as it does `configuredNames`. Bump `CACHE_FORMAT_VERSION` to `'11'`.

### 4. Testbench migrations in package analysis

`ApplicationResolver::resolve()` passes Testbench a resolving callback, as `Commander::resolveApplicationCallback()` does:

```php
return Testbench::create(
    resolvingCallback: static function (Application $app) use ($config): void {
        (new LoadMigrationsFromArray($config['migrations'] ?? []))->bootstrap($app);
    },
    options: [...],
);
```

- **What it registers:** the yaml `migrations` paths, plus Testbench's default migrations unless `workbench.install` is `false` or `TESTBENCH_WITHOUT_DEFAULT_MIGRATIONS` is set. `migrations: false` registers nothing. Seeders stay off by default.
- **Configuration:** the discovered `TestbenchServiceProvider` binds the same Testbench config instance (verified), so `workbench()` reads the package's settings.
- **Caching:** the migration fingerprint hashes the ordered file list, so changing a path or toggle invalidates the cache. Nothing else from `Commander` is needed.
- **Components' own analysis** now reads Testbench's default migrations too.

## Implementation

`src/phpstan/`:

- **`src/Schema/BlueprintMacroExtension.php`, `BlueprintMacroCall.php`, `NestedSetBlueprintMacros.php`, `SearchableBlueprintMacros.php`:** new, as designed above.
- **`src/Properties/MigrationHelper.php`:**
  - Inject `#[AutowiredExtensions(of: BlueprintMacroExtension::class)] ExtensionsCollection`.
  - Add memoized `getBlueprintMacros(): array<string, BlueprintMacroExtension>`, which does the ownership and conflict checks.
  - Add `getSchemaConfiguration()`, which returns `blueprintMacros` (name → [registering class, extension class]).
  - Pass the described macros to the aggregator.
- **`src/Properties/SchemaAggregator.php`:**
  - The constructor takes the described macros. Connection opt-ins go through the existing `ConnectionConfigResolver`.
  - Remove `KNOWN_MACROS`, `$knownMacros`, `getKnownMacros()`, `callsKnownMacro()`, the four macro switch cases, and the `NestedSet`, `NestedSetServiceProvider` and `SearchableSchema` imports.
  - Dispatch described macros at the top of `processStatementAlterMethod()`'s `default` branch. It returns `false` when a `dropColumn(null)` or the chain rule makes the table unknown.
  - Add `creating: bool` to `TableScope`, set in `alterTable()`.
  - Make the connection and `shouldRun()` changes above.
  - Add each applied extension's class files to `sources`.
- **`src/Properties/ConnectionConfigResolver.php`:**
  - In `getSchemaConfiguration()`, replace `macros` with the configured `migrationConnections`.
  - Add `getConfiguredBoolean()`.
  - Inject `#[AutowiredParameter] array $migrationConnections`.
  - Add `resolveMigrationConnection(string $class): ?array{bool, ?string}`, which returns `null` for an unmapped class, plus its `resolvedConnections` record and accessor.
- **`src/Properties/ModelPropertyHelper.php`:** pass `connectionConfigResolver->getSchemaConfiguration() + migrationHelper->getSchemaConfiguration()` to `remember()`.
- **`src/Properties/MigrationCache.php`:** store and compare `configuredBooleans` and `resolvedConnections`, and bump the format version.
- **`src/ApplicationResolver.php`:** add the resolving callback.
- **`extension.neon`:** add `migrationConnections: []` and its schema.

## Documentation

All prose goes in `src/docs/static-analysis.md`, as the single canonical place.

- **New `## Packages` section**, between "Custom PHPDoc Types" and "Fixing and Ignoring Diagnostics", with these H3s:
  - **Analyzing Packages:** moved from Installation, keeping the anchor and TOC entry. Testbench boots the package's providers and reads `testbench.yaml`, including `migrations` and the default-migration settings. Code that relies on registrations made only when a feature is enabled needs that feature enabled in `testbench.yaml`'s `env`.
  - **Shipping Analysis Support:**
    - Put a neon file in the package and list it under `extra.phpstan.includes`. Applications using the extension installer, as new Hypervel applications do, load it automatically; others add it to `includes`. Include it in the package's own `phpstan.neon` too.
    - It may set hypervel/phpstan parameters such as `eloquentBuilderMacros` (entries merge, and an application entry with the same key wins), register describers, and use PHPStan's own extensions and stubs.
    - Classes the file references must load through the package's normal `autoload`, not `autoload-dev`, because applications analyze with them.
    - Macros registered during boot need nothing extra (link to Configuration and Services).
  - **Describing Blueprint Macros:** the billing example, the neon `services` entry with the tag, the call methods, ownership, and the chained-call rule in one sentence.
  - **Migration Connections:**
    - A `getConnection()`, whether declared or inherited, is read when it returns a name, `$this->connection`, or a `config()` value (the `AiMigration` shape).
    - `migrationConnections` covers getters that ask a service. Show the billing example's `Class@method` entry. The service is called only when a selected migration uses the mapped class.
- **"Migrations and Schema Dumps" limits paragraph:**
  - Macros are unknown unless described, and nested set and searchable are described already.
  - Replace the connection sentence with the getter rules and link.
  - Add: a `shouldRun()` returning `false` skips the migration; a result the analysis can't read allows for both outcomes.
- **Configuration table:** add a `migrationConnections` row with default `[]`, pointing to Migration Connections.
- **`src/docs/packages.md`:** add a "Static Analysis" H3 after "Testing Packages", plus its TOC entry. One sentence linking to `static-analysis#packages`.

## Tests

The analyzer suite runs with `-c phpunit.phpstan.xml.dist`. The fictional package lives in `tests/PHPStan/Fixtures/Bootstrap/Billing/` and is shared by the tests below:
- `BillingServiceProvider`: registers the macros through `BillingSchema::register()` and loads `database/migrations`;
- `BillingSchema`;
- `BillingBlueprintMacros`;
- `extension.neon`: registers the describer with the tag, as a package ships it;
- an `invoices` migration that uses the macros;
- an `Invoice` model;
- `Usage.php`, which returns `$invoice->invoice_reference` from an `int` function and reads a misspelled attribute.

Reader-only fixtures go in `tests/PHPStan/Fixtures/SchemaAggregator/`:
- `BillingMigration`, a base class with `getConnection()` returning `config('billing.connection')`;
- `UsesBillingConnection`, a trait with the same getter;
- `BillingConfiguration`, whose constructor counts instances;
- `SentinelMigration`, a base class whose constructor and two-statement getter increment a static counter.

Tests that register macros save and restore `Blueprint::$macros`, as `itForgetsTablesAnotherSearchableMacroChanges` does.

- **`Unit/SchemaAggregatorTest.php`:**
  - `apply()` takes the described macros (default: the built-in descriptions) and, for the mapped-class cases, a `ConnectionConfigResolver` built with a `migrationConnections` mapping.
  - The billing cases use the shared billing fixtures.
  - The existing nested set, searchable and replaced-macro tests stay unchanged.
  - Add:
    - **Billing macros:**
      - create and alter, including `billingCustomer` being nullable on alter;
      - `currencyAmount` twice adds `currency` once;
      - a dynamic column name leaves an unnamed column and keeps the known columns;
      - an unknown `currency` presence leaves it unclaimed.
    - **Chains:** `billingCustomer()->cascadeOnDelete()` and `invoiceReference()->comment()` keep the facts; `invoiceReference()->nullable()` makes the table unknown.
    - **Undescribed macro:** forgets the table, unless an existing test already covers it.
    - **Sources:** the describer file is among the sources.
    - **Connections:** inherited and trait getters; the migration's own override wins; an unreadable inherited getter keeps every connection unknown; a mapped class uses its resolved value; an override below the mapped class is read normally; the sentinel counter stays 0.
    - **`shouldRun()`:**
      - literal `true` and `false`;
      - `Config::boolean('billing.enabled')` both ways (`OverridesConfig`);
      - an absent key with a `false` default skips;
      - an absent key without a default, or a non-boolean value, merges;
      - an inherited `shouldRun()`.
- **`Unit/MigrationHelperTest.php`:** `getBlueprintMacros()` matches live macros, excludes a replaced closure, and throws for conflicting describers.
- **`Unit/ConnectionConfigResolverTest.php`:**
  - `getConfiguredBoolean()` records booleans.
  - `resolveMigrationConnection()`:
    - returns `null` for an unmapped class;
    - resolves a mapped class once and honors a container rebinding;
    - never instantiates an unused mapping's service;
    - throws for a value without `@method`.
- **`Unit/MigrationCacheTest.php`:**
  - A changed configured boolean misses the cache, and an equal one hits it.
  - So does a changed recorded connection result, while a warm run resolves only the recorded classes.
  - `testReplacingAKnownSchemaMacroInvalidatesTheEntry` fingerprints `MigrationHelper::getSchemaConfiguration()`, which now owns the macro entry.
- **`BootstrapTest.php`:** package analysis registers migration paths. Use `runBootstrap()` to print `$app->make('migrator')->paths()`, with data cases:
  - a listed path plus the defaults;
  - `migrations: false`;
  - `workbench: {install: false}`;
  - `TESTBENCH_WITHOUT_DEFAULT_MIGRATIONS` set through `testbench.yaml`'s `env`.
- **`BootstrapTest.php`, public entry point:**
  - Analyze the billing package as its author does: a temporary package whose `composer.json` declares `BillingServiceProvider`, with the package's `extension.neon` included. `analyse()` gains an `$includes` argument for this.
  - Expect exactly `Usage.php` `return.type` (the attribute is a `string`) and `property.notFound` (the misspelling).
  - Consumers include the same file, so one run covers both paths without installing packages or testing Composer.

## Verification

- Run each changed or new test file immediately.
- Then run `composer test:phpstan`, then `composer analyse`. Treat any diagnostic newly reported because components' analysis now reads Testbench's default migrations as a finding: investigate it, don't suppress it.
- Finish with `composer lint:fix` before review.
