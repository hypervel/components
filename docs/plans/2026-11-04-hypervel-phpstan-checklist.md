# Hypervel PHPStan remaining-file checklist

Working checklist for [2026-11-04-hypervel-phpstan.md](2026-11-04-hypervel-phpstan.md). Remove entries once handled. Paths are relative to `/home/binaryfire/workspace/references/larastan` unless they start with `src/` or `tests/` in this repository.

Actions: **copy** (cp, read, adapt), **move** (mv existing Hypervel file), **merge** (combine into the named file), **add** (new Hypervel file), **omit** (record per the porting policy).

Destinations: package root `src/phpstan/`; upstream `src/` -> `src/phpstan/src/`; `stubs/` -> `src/phpstan/stubs/`; `tests/` -> `tests/PHPStan/`.

## Package root

- `extension.neon` services: restore upstream's `AbortIfFunctionTypeSpecifyingExtension` (abort/throw), `AppEnvironmentReturnTypeExtension`, `CheckDispatchArgumentTypesCompatibleWithClassConstructorRule` (bus/events `Dispatchable`) and `migrationsParser` entries with their classes; omit the container ArrayAccess entries
- `extension.neon` and Components integration: set `reportIgnoresWithoutComments: true`; add `phpstan-deprecation-rules` includes to `phpstan.neon.dist`/`phpstan.types.neon.dist`
- omit: `phpcs.xml.dist`, `phpstan.neon.dist`, `phpstan-baseline.neon`, `phpunit.xml.dist`, `phpunit-bootstrap.php`, `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md`, `RELEASE.md`, `UPGRADE.md`, `assets/` (repository tooling; Components owns its own)
- merge into `src/docs/phpstan.md`: `docs/custom-config-parameters.md`, `docs/custom-types.md`, `docs/errors-to-ignore.md`, `docs/features.md`, `docs/rules.md`
- omit: `e2e/` Laravel project baselines (replaced by the plan's real-project checks)

## Existing Hypervel extensions

- merge: `src/phpstan/src/ReturnTypes/Helpers/HelperFunctionReturnTypeExtension.php` (moved from Foundation) -> `ReturnTypes/Helpers/{ResponseExtension,ValidatorExtension}.php`, plus a Hypervel `ViewExtension.php` keeping its `view()` argument-count inference (upstream relies on the stub's false explicit-null return)

## Runtime prerequisites

- `src/bus/src/PendingChain.php`, `src/bus/src/Queueable.php`, `src/bus/src/ChainedBatch.php`, `src/support/src/Testing/Fakes/{QueueFake,PendingChainFake}.php` and their existing tests (chain/fake precedence)

## src

- copy: `ClosureTypes/RelationshipQueryCallbackExtension.php`
- copy: `Concerns/{InspectsQueuedJobs,LoadsAuthModel}.php`
- copy: `Internal/{ConfigHelper,ConsoleApplicationHelper,ConsoleApplicationResolver,RecursionGuard}.php`
- omit: `Internal/ComposerHelper.php` (`Support\Composer::vendorPath()` resolves the actual install)
- copy: `Methods/{AuthsMethodsExtension,BuilderHelper,FacadesMethodsExtension,HigherOrderCollectionProxyExtension,HigherOrderTapProxyExtension,Macro,MacroMethodsClassReflectionExtension,ManagersMethodsExtension,ModelFactoryMethodsClassReflectionExtension,StorageMethodsClassReflectionExtension}.php`
- merge into moved forwarding extensions: `Methods/{EloquentBuilderForwardsCallsExtension,ModelForwardsCallsExtension,RelationForwardsCallsExtension}.php`
- omit: `Methods/ContractsMethodsExtension.php` (interfaces stay honest)
- copy: `Properties/*` (17 files, including `Schema/MySqlDataTypeToPhpTypeConverter.php`)
- copy: `Reflection/{AnnotationScopeMethodParameterReflection,AnnotationScopeMethodReflection,DynamicWhereParameterReflection,EloquentBuilderMethodReflection,ReflectionHelper,StaticMethodReflection}.php`
- merge into moved scope reflections: `Reflection/ModelScopeMethodReflection.php`
- copy: `ReturnTypes/*` except below and the ported container typing and translation files (42 files, including `ConsoleCommand/` and `Helpers/`)
- omit: `ReturnTypes/ContainerArrayAccessDynamicMethodReturnTypeExtension.php` (container ArrayAccess is unsupported), `ReturnTypes/ApplicationMakeDynamicReturnTypeExtension.php` (the container extension already covers the Application contract)
- copy: `Rules/*` except below and the ported `NoMissingTranslationsRule`/`UnusedViewsRule` (26 files, including `Queue/` and `UselessConstructs/`)
- omit: `Rules/DeferrableServiceProviderMissingProvidesRule.php`, `Rules/OctaneCompatibilityRule.php`
- copy: `SQL/*` (7 files)
- copy: `Support/{ArrKeyLookup,CollectionHelper,ConfigParser,HigherOrderCollectionProxyHelper}.php`
- copy: `Types/*` except the ported `ViewString*` files (13 files)

## New Hypervel source

- add: `Rules/Coroutine/` cancellation, worker-lifetime, static-state, unbounded-cache, runtime-config, transaction-coroutine and raw-sleep rules
- add: `Rules/` legacy model scope and legacy model property rules; `Rules/Queue/NoLegacyQueuePropertiesRule.php`
- add: `Rules/Validation/` pipe-separated validation rule
- add: PostgreSQL and SQLite schema parser adapters under `SQL/`

## stubs

- copy: `common/*` except below and the ported view stubs (76 files)
- merge into `common/Facades.stub` and `common/Helpers.stub` with their topics: the remaining facade and helper entries (only View, Route and `view()` are ported; `trans()`/`__()` are omitted, as the stub explains)
- add to source with the routing stubs: upstream `common/Route.stub`'s `methods()` and `middleware()` element types as `Route` PHPDoc in `src/routing`, with a `types/` fixture (the stub keeps only the empty declaration stub validation needs)
- omit: `common/Redis/{PredisConnection,PredisFacade}.stub`
- add to source: upstream `common/MailMessage.stub`'s property shapes (`$view`, `$from`, `$replyTo`, `$attachments`, `$rawAttachments`) as `MailMessage` PHPDoc in `src/notifications`, with a `types/` fixture
- omit: `common/Contracts/Translation.stub` (only upstream's omitted `trans()` helper stub references it)
- merge into `common/`: `12.20.0/Enumerable.stub`, `12.41.0/Http/PendingRequest.stub`, `13.3.0/{EloquentBuilder,Model}.stub`, `13.35.0/{BelongsToMany,HasOneOrMany}.stub`, `13.37.0/HasOneOrManyThrough.stub`

## tests

- copy: `bootstrap.php`, `enum-definition.php`, then add the test bootstrap to `tests/PHPStan/phpstan-tests.neon`; omit `laravel-test.sh`
- copy: `application/` fixture app except the ported lang and views (109 files: app, config, factories, migrations, schema dump)
- merge into `Integration/IntegrationTest.php`: the remaining upstream entries with `Integration/data/*` (46 files), switching its config to `Type/data/config-check-model-properties.neon` with model properties; copy `Integration/BleedingEdgeIntegrationTest.php`
- copy: `Reflection/MacroMethodsClassReflectionExtensionTest.php`
- copy: `Rules/*Test.php` except below and the ported view/translation rule tests (24 classes), the remaining `Rules/data/*`, `Rules/Queue/data/*`, `Rules/UselessConstructs/data/*`
- omit: `Rules/{DeferrableServiceProviderMissingProvidesRuleTest,OctaneCompatibilityRuleTest}.php`, `Rules/data/{CorrectDeferrableProvider,CorrectDeferrableProviderIndirect,IncorrectDeferrableProvider}.php`, `Rules/data/octane.neon`, `Rules/data/ContainerInjection.php`
- copy: `Type/*Test.php` except below, `GeneralTypeTest.php` and `MethodsClassReflectionExtensionTest.php` (10 classes) with the remaining `Type/data/*` (148 files)
- merge into `Type/MethodsClassReflectionExtensionTest.php` with the macro port: `macros.php` and `macros-php-81.php` (unconditional; Hypervel requires PHP 8.4)
- merge: the remaining `Type/GeneralTypeTest.php` data entries, and its `data/config-with-migrations.neon` configuration once migrations are ported
- merge: `Type/{RedisDriverNullTypeTest,RedisDriverPhpRedisTypeTest}.php` and their data into one phpredis test; omit `Type/RedisDriverPredisTypeTest.php` with `redis-driver-predis.*`
- omit: `Type/data/container-array-access.php`; investigate `Type/data/contracts.php`
- copy: `Unit/*Test.php` except `Support/{BootstrapErrorHandlerTest,RecursiveDirectoryIteratorTest}.php` (11 classes, including `SQL/` and `Support/`), `Unit/data/*`
