# Static analysis support for packages

Implemented. The remaining [abstract get hook test cases](../todo.md#testing) depend on a PHPStan reflection fix.

## Purpose

Packages that analyze themselves, and applications that analyze code using packages, lose schema facts in four places. This plan fixes those places and documents how packages ship PHPStan support:

- **Blueprint macros** make their table unknown. Only the hardcoded nested set and Scout `searchable` macros are read.
- **Migration connections.** An inherited getter or connection property default can make the migration's target unknown, as can a getter that asks a service. A schema change on an unknown connection can erase facts for every connection.
- **`shouldRun()`.** The reader applies migrations that `shouldRun()` skips.
- **Testbench migrations.** Package analysis ignores the migrations a package's Testbench setup lists.

Distribution needs documentation only. PHPStan's extension installer discovers `extra.phpstan.includes` on installed dependencies regardless of Composer type. It sorts them by package name. PHPStan loads the project config last. String-valued maps such as `eloquentBuilderMacros` keep distinct keys, and the later value wins for the same key. The root package includes its own file explicitly.

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

#[ExtensionInterface(self::TAG)]
interface BlueprintMacroExtension
{
    public const string TAG = 'hypervel.blueprintMacroExtension';

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

`BlueprintMacroCall` has an `@internal` constructor. Its methods use Blueprint's own vocabulary:

| Method | Meaning |
|---|---|
| `name(int $position, string $parameter, ?string $default = null): ?string` | A string argument, by position or name. Returns `null` when the argument is not a literal or class constant (`getNameArgument()`). |
| `creating(): bool` | Whether the call runs inside `Schema::create()`. |
| `hasColumn(string $name): ?bool` | Match through `resolveColumnName()`. Return `true` for a known, typed column and `false` for a confirmed absence on a complete table. Return `null` for an ambiguous name, a column of unknown type (it may exist in only one branch), or an unlisted column on an incomplete table. |
| `addColumn(string $type, ?string $name, bool $nullable = false, bool $unsigned = false): void` | Adds a column. `$type` is a Blueprint type such as `string`, `bigInteger` or `uuid`, lowercased for the reader's type tables. A `null` name adds an unnamed column, which keeps the known columns. |
| `dropColumn(?string $name): void` | Drops a column. A `null` name, or one `resolveColumnName()` can't resolve, makes the table unknown. |

The call sees the table at the start of the macro, including earlier statements in the same Blueprint callback. A second macro call sees the first one's effects. The database snapshot used by `Schema::hasColumn()` predates the whole callback and uses different name matching. Record the macro's effects, then apply them in order once `apply()` returns, through the reader's existing helpers:
- `setColumn()` and `addUnnamedColumn($table, false)` for adds;
- `resolveColumnName()` and `SchemaTable::dropColumn()` for drops.

Strings are the only argument type, because no describer needs another. There is no "unknown" method: an undescribable call means the macro isn't described, which keeps today's behavior of forgetting the table.

**Ownership.** A description applies only while `Blueprint::$macros[$name]` is a Closure whose scope class equals the declared class. Public Blueprint methods take precedence over macros with the same name, even when the case differs. A macro that the application replaces makes the table unknown.

Macro names are case-sensitive, as in `Macroable::hasMacro()`.

**Composition.** Collect tagged extensions when analysis first needs the schema. If two extensions describe the same macro for the same live class, throw a `LogicException` naming both extensions then. Memoize the matched extensions and each live closure's return category for that analysis.

**Chained calls.** With no invoked link, apply just the macro's described effects. Use the live closure's native return type for a chain:

| Return category | Effect of an invoked link |
|---|---|
| `ForeignKeyDefinition`, `IndexDefinition` (including subclasses), or `void` | Ordinary chains leave column facts intact, as for `foreign()` and `index()`. |
| `ColumnDefinition` or a subclass | Metadata such as `comment()` keeps the facts. An invoked `nullable`, `unsigned`, `type` or `change` makes the table unknown. A macro can return a column queued earlier, even when it added no columns itself. |
| Every other declaration, or no declared return type | Any invoked link makes the table unknown. For example, a macro returning the Blueprint can be followed by `string('reference')`. |

Keep the shared reader safeguards for Fluent mutators and dynamically named links. In `processColumnUpdate()`, keep each invoked link in the existing chain, using an unknown name for a dynamic link. Discard first-class callable links and omit unknown names from the named modifier map. Trim the foreign-key tail after a built-in foreign ID's `constrained()` or `references()` as today. After the ignored Blueprint methods return, an unknown name in the remaining chain makes the table unknown, alongside the existing `fill`, `set`, `offsetSet` and `offsetUnset` check. Use the remaining invoked links to decide whether a described macro was chained.

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

`evaluateGetConnection()` uses `findMethod()` for the effective getter, whether declared locally, in a trait or in a parent. This covers the migration's default connection and explicit selectors such as `Schema::connection($this->getConnection())`.

| Result | Connection |
|---|---|
| `null` (Migration's own) | Read the effective `$connection` property through `evaluateConnectionProperty()`. |
| Declared by a class listed in `migrationConnections` | That entry's resolved value. |
| Any other declaration | Today's single-`return` evaluation, now also for inherited and trait methods. The string-return-type rule is kept. |
| `false` | Unknown. |

Keep the existing runtime-write check. Read the migration's own `$connection` declaration if present. Otherwise inspect the parent's effective property using its reflected default expression, as in `LegacyPropertyHelper`. Every migration inherits the framework's property, so a valid trait composition must agree with the effective default. In either position, read only a plain stored string or null literal. A typed property with no initializer, a locally declared get hook, a concrete inherited get hook, or an unreadable default leaves the connection unknown. A plain declaration satisfies an abstract get hook and supplies its own default. This also fixes the current reader taking a locally declared property's backing default as the result of its get hook. The framework base declares an explicit null default. Remove `inheritsFromMigration()` once both of its callers use effective declarations.

`addClassStatements()` already records the migration's parents and traits as sources. Mapping keys are base migration classes. A base that imports the getter from a trait can be mapped because `findDeclaredMethod()` reports the using class. A trait used directly by the migration follows the ordinary getter evaluator. Unsupported trait method adaptations stay unknown.

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
  - A string or `null` result is known; anything else is unknown. Apply the getter's existing non-nullable `string` rule before treating a null result as the default connection.
  - Mappings no selected migration uses never instantiate their service.
- **Cache:** key `resolvedConnections` by mapped migration class. Store the result and concrete service class so a rebinding that returns the same name still refreshes the source dependencies. On a warm run compare only the recorded mappings, after checking the stored config reads and source hashes. An edit to an inherited guard can skip the migration just as a changed config flag can.
- **Sources:** record the resolved implementation's files via `SourceFiles::forInstance()` when a mapping is used. Its methods and property defaults, including referenced constants, can decide the connection. These are global schema sources.
- **Migration files** are parsed, never loaded.
- **Overrides:** an override below the mapped class is read normally.
- **Unlisted getters:** unlisted getters the reader can't evaluate keep today's behavior. They are unknown and never run.

**Rejected alternatives.**
- **Resolving migrations and calling `getConnection()`.** `Migrator::resolvePath()` runs top-level PHP and constructors. Making that protected Laravel method public breaks subclasses that redeclare it as protected. And the guide promises PHPStan doesn't execute migrations.
- **Calling service chains found in getter source.** That is implicit execution.

### 3. `shouldRun()`

`addClassStatements()` mirrors `Migrator::runUp()`: only a `Migration` subclass can skip itself. Apply a plain migration object unconditionally. If the base cannot be resolved, a guard that could skip allows for both outcomes. For a known subclass, decide the guard as below, then find `up()`, then resolve the connection if the body will be read. Keep parent and trait source tracking even for skipped migrations.

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
  - Add `getConfiguredBoolean(string $key): array{bool, ?bool}` (present, and the value when it is a boolean). Read through the resolver's existing config helper so the key's source is tracked. Record the state in `$configuredBooleans` with a `getConfiguredBooleans()` accessor.
  - `MigrationCache` stores and compares `configuredBooleans` as it does `configuredNames`. Check guard inputs before replaying recorded service callbacks, so a changed flag can invalidate the entry without instantiating the service. Bump `CACHE_FORMAT_VERSION` to `'11'`.

### 4. Testbench migrations in package analysis

`ApplicationResolver::resolve()` passes Testbench a resolving callback, as `Commander::resolveApplicationCallback()` does:

```php
return Testbench::create(
    resolvingCallback: static function (Application $app) use ($config): void {
        $app->instance(Config::class, $config);

        (new LoadMigrationsFromArray($config['migrations'] ?? []))->bootstrap($app);
    },
    options: [...],
);
```

- **What it registers:** Testbench's YAML migration paths and, by default, its built-in migrations. `workbench.install: false` or an environment value of boolean `true` for `TESTBENCH_WITHOUT_DEFAULT_MIGRATIONS` omits the defaults. `migrations: false` skips all registrations from this loader. Provider-registered paths still work. Pass only the migrations option to keep seeders off.
- **Configuration:** `Config` above is the existing `Hypervel\Testbench\Contracts\Config` import. Commander supplies the loaded config through `Workbench::start()`. Binding the same instance in analysis lets `workbench()` see the package's settings even when `TestbenchServiceProvider` discovery is disabled.
- **Discovery and caching:** `MigrationHelper` already reads the migrator's paths, and an explicit `databaseMigrationsPath` replaces them. The migration fingerprint already hashes the resulting ordered files, including their paths and contents.
- **Components' own analysis** now reads Testbench's default migrations too.

## Implementation

`src/phpstan/`:

- **`src/Schema/BlueprintMacroExtension.php`, `BlueprintMacroCall.php`, `NestedSetBlueprintMacros.php`, `SearchableBlueprintMacros.php`:** new, as designed above.
- **`src/Properties/MigrationHelper.php`:**
  - Inject `PHPStan\DependencyInjection\Container` and use its public `getServicesByTag(BlueprintMacroExtension::TAG)` at the first schema load. PHPStan validates tagged services against the extension interface.
  - Match and memoize the live macros by exact name, with their registering class, extension and return category. Pass these records to the aggregator. In `getSchemaConfiguration()`, fingerprint the name, registering class, extension class and return category using scalar values. A return type can change chain behavior even if both classes keep the same names.
- **`src/Properties/SchemaAggregator.php`:**
  - The constructor takes the described macros. Connection opt-ins go through the existing `ConnectionConfigResolver`.
  - Remove `KNOWN_MACROS`, `$knownMacros`, `getKnownMacros()`, `callsKnownMacro()`, the four macro switch cases, and the `NestedSet`, `NestedSetServiceProvider` and `SearchableSchema` imports.
  - Dispatch described macros at the top of `processStatementAlterMethod()`'s `default` branch. It returns `false` when a `dropColumn(null)` or the chain rule makes the table unknown.
  - Add `creating: bool` to `TableScope`, set in `alterTable()`.
  - Make the connection and `shouldRun()` changes above.
  - Include each matched extension's `SourceFiles::forInstance()` files in its memoized record. Record them on the affected table when applied. Model lookups already track the table's sources, schema guards promote them when they decide another table's facts, and the migration cache hashes them.
- **`src/Properties/ConnectionConfigResolver.php`:**
  - In `getSchemaConfiguration()`, replace `macros` with the configured `migrationConnections`.
  - Add `getConfiguredBoolean()`.
  - Inject `#[AutowiredParameter] array $migrationConnections`.
  - Add `resolveMigrationConnection(string $class): ?array{bool, ?string}`, which returns `null` for an unmapped class, plus its `resolvedConnections` record and accessor.
- **`src/Properties/ModelPropertyHelper.php`:** pass both helpers' schema configuration to `remember()`. Load schema facts only if PHPStan sees the actual migration implementation. Its stub validator can see a stub declaring `Migration` or no declaration at all. Leave schema facts unknown there so stub-only reflection cannot read or populate the application's migration cache.
- **`src/Properties/MigrationCache.php`:** store and compare `configuredBooleans` and `resolvedConnections`, and bump the format version. Include the installed PHPStan version in the fingerprint because its parser and reflection determine schema facts.
- **`src/ApplicationResolver.php`:** add the resolving callback.
- **`extension.neon`:** add `migrationConnections: []` and its schema.

## Documentation

All prose goes in `src/docs/static-analysis.md`, as the single canonical place.

- **New `## Packages` section**, between "Custom PHPDoc Types" and "Fixing and Ignoring Diagnostics", with these H3s:
  - **Analyzing Packages:** moved from Installation, keeping the anchor and TOC entry. Testbench boots the package's providers and reads `testbench.yaml`, including `migrations` and the default-migration settings. Code that relies on registrations made only when a feature is enabled needs that feature enabled in `testbench.yaml`'s `env`.
  - **Shipping Analysis Support:**
    - Put a NEON file in the package and list it under `extra.phpstan.includes`. The project running analysis needs `hypervel/phpstan` as a development dependency to use its parameters and extension types. New Hypervel applications include it and the extension installer.
    - For self-analysis, explicitly include the file in the package's own `phpstan.neon`. Consumers using the installer receive the dependency's file automatically. Other consumers include it manually. PHPStan rejects duplicate includes.
    - If the package lists `phpstan/phpstan` in Composer `require`, the installer needs a constraint with both lower and upper bounds. A package can ship analysis support with PHPStan in `require-dev`.
    - It may set hypervel/phpstan parameters such as `eloquentBuilderMacros` (entries merge, and an application entry with the same key wins), register describers, and use PHPStan's own extensions and stubs.
    - Classes the file references must load through the package's normal `autoload`, not `autoload-dev`, because applications analyze with them.
    - Link to Configuration and Services for checking signatures of macros registered during boot. Schema effects are described in the next subsection.
  - **Describing Blueprint Macros:** the billing example, its tagged NEON `services` entry, the call methods, ownership and a brief explanation of chained calls.
  - **Migration Connections:**
    - A `getConnection()`, whether declared or inherited, is read when it returns a name, `$this->connection`, or a `config()` value (the `AiMigration` shape). The reader recognizes plain string or null property defaults declared locally or inherited from a base migration, including compatible trait compositions.
    - `migrationConnections` covers getters that ask a service. Show the billing example's base-class key and `Class@method` value. Explain direct trait getters and subclass overrides. A skipped migration never calls its mapped service.
- **"Migrations and Schema Dumps" limits paragraph:**
  - Macros are unknown unless described, and nested set and searchable are described already.
  - Add the getter rules and link to Migration Connections. Keep the existing explanation of runtime writes to the connection property.
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

Tests that register macros save and restore `Blueprint::$macros`, as `itForgetsTablesAnotherSearchableMacroChanges` does. Supply the new dependencies at direct constructor call sites, including `ModelPropertyHelperTest` and `SquashedMigrationHelperTest`. Reuse their existing setup.

- **`Unit/SchemaAggregatorTest.php`:**
  - `apply()` takes the described macros (default: the built-in descriptions) and, for the mapped-class cases, a `ConnectionConfigResolver` built with a `migrationConnections` mapping.
  - The billing cases use the shared billing fixtures.
  - The existing nested set, searchable and replaced-macro tests stay unchanged.
  - Add:
    - **Billing macros:**
      - create and alter, including `billingCustomer` being nullable on alter;
      - `currencyAmount` twice adds `currency` once;
      - a dynamic column name leaves an unnamed column and keeps the known columns;
      - uncertain `currency` presence leaves it unclaimed, including when only one earlier branch added it.
    - **Chains:** `billingCustomer()->cascadeOnDelete()` and `invoiceReference()->comment()` keep the facts. A column modifier makes the table unknown, including when the macro returns a previously queued column. Cover a Blueprint-returning or untyped macro followed by `string('foo')` with a test-local macro and describer. Cover a discarded first-class link in the same cases. Add a dynamic built-in column modifier to the existing Fluent-mutator cases.
    - **Undescribed macro:** forgets the table, unless an existing test already covers it.
    - **Sources:** assert the describer file is a source of the affected table and the resolved connection service's implementation is a global schema source.
    - **Connections:** rename `itReadsOnlyTheConnectionInheritedFromMigration` and assert the target for readable inherited getters and property defaults. Use `DefinesConnection` on `ConnectionPropertyMigration` for a valid trait composition with the same `'pgsql'` default. Cover a child's literal override, a typed property without an initializer, and a get hook declared locally or inherited through a plain redeclaration. Uninitialized and hooked reads must resolve as unknown. Keep the runtime-write, unreadable-getter and unsupported method-adaptation cases. Cover a mapped base, an override below it, and the sentinel counter staying zero.
    - **`shouldRun()`:**
      - literal `true` and `false`;
      - a false guard on a plain migration object still applies, and one on an unresolved base merges;
      - `Config::boolean('billing.enabled')` both ways (`OverridesConfig`);
      - an absent key with a `false` default skips;
      - an absent key without a default, or a non-boolean value, merges;
      - an inherited `shouldRun()` and a false guard on a mapped migration whose service must never be resolved.
- **`Unit/MigrationHelperTest.php`:** `getBlueprintMacros()` matches live macros, excludes a replaced closure, and throws for conflicting describers. The reader also verifies a macro registration cannot replace a public Blueprint method.
- **`Unit/ConnectionConfigResolverTest.php`:**
  - `getConfiguredBoolean()` records booleans.
  - `resolveMigrationConnection()`:
    - returns `null` for an unmapped class;
    - resolves a mapped class once and honors a container rebinding;
    - never instantiates an unused mapping's service;
    - throws for a value without `@method`.
- **`Unit/MigrationCacheTest.php`:**
  - A changed configured boolean misses the cache before its previously enabled mapping is invoked. An equal boolean hits it.
  - A changed connection result or rebound implementation misses. A warm run resolves only the recorded classes.
  - In `testReplacingAKnownSchemaMacroInvalidatesTheEntry`, use a fresh `MigrationHelper` for each simulated boot and fingerprint its `getSchemaConfiguration()`. Verify a changed live return category also invalidates the entry with the same owner and extension.
- **`BootstrapTest.php`:** package analysis registers migration paths. Use `runBootstrap()` to print `$app->make('migrator')->paths()`, with data cases:
  - a listed path plus the defaults;
  - `migrations: false`;
  - `workbench: {install: false}` with a listed path and no discovered Testbench provider (the listed path survives and defaults are absent);
  - `TESTBENCH_WITHOUT_DEFAULT_MIGRATIONS: true` through `testbench.yaml`'s `env`.
- **`Unit/ModelPropertyHelperTest.php`:** a reflected migration stub from a different file leaves model facts unknown and never enters migration discovery or caching.
- **`BootstrapTest.php`, public entry point:**
  - Analyze the billing package as its author does: a temporary package whose `composer.json` declares `BillingServiceProvider`, with the package's `extension.neon` included. `analyse()` gains an `$includes` argument for this.
  - Expect exactly `Usage.php` `return.type` (the attribute is a `string`) and `property.notFound` (the misspelling).
  - Consumers include the same file, so one run covers both paths without installing packages or testing Composer.

## Verification

- Run each changed or new test file immediately.
- Run `composer lint:fix`, then `composer analyse`, then `composer test:phpstan`. Treat any diagnostic newly reported because components' analysis now reads Testbench's default migrations as a finding: investigate it, don't suppress it.
