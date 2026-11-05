# Hypervel PHPStan remaining-file checklist

Working checklist for [2026-11-04-hypervel-phpstan.md](2026-11-04-hypervel-phpstan.md). Remove entries once handled. Paths are relative to `/home/binaryfire/workspace/references/larastan` unless they start with `src/` or `tests/` in this repository.

Actions: **copy** (cp, read, adapt), **move** (mv existing Hypervel file), **merge** (combine into the named file), **add** (new Hypervel file), **omit** (record per the porting policy).

Destinations: package root `src/phpstan/`; upstream `src/` -> `src/phpstan/src/`; `stubs/` -> `src/phpstan/stubs/`; `tests/` -> `tests/PHPStan/`.

## Package root

- `extension.neon` services: restore upstream's `AbortIfFunctionTypeSpecifyingExtension` (abort/throw), `AppEnvironmentReturnTypeExtension`, `CheckDispatchArgumentTypesCompatibleWithClassConstructorRule` (bus/events `Dispatchable`) and `migrationsParser` entries with their classes; omit the container ArrayAccess entries
- `extension.neon` and Components integration: set `reportIgnoresWithoutComments: true`; add `phpstan-deprecation-rules` includes to `phpstan.neon.dist`/`phpstan.types.neon.dist`
- omit: `phpcs.xml.dist`, `phpstan.neon.dist`, `phpstan-baseline.neon`, `phpunit.xml.dist`, `phpunit-bootstrap.php`, `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md`, `RELEASE.md`, `UPGRADE.md`, `assets/` (repository tooling; Components owns its own)
- merge into `src/docs/static-analysis.md`: `docs/custom-config-parameters.md`, `docs/custom-types.md`, `docs/errors-to-ignore.md`, `docs/features.md`, `docs/rules.md`
- omit: `e2e/` Laravel project baselines (replaced by the plan's real-project checks)

## Runtime prerequisites

- `src/bus/src/PendingChain.php`, `src/bus/src/Queueable.php`, `src/bus/src/ChainedBatch.php`, `src/support/src/Testing/Fakes/{QueueFake,PendingChainFake}.php` and their existing tests (chain/fake precedence)

## src

- copy: `ClosureTypes/RelationshipQueryCallbackExtension.php`
- copy with the queue rules: `Concerns/InspectsQueuedJobs.php`
- omit: `Internal/ComposerHelper.php` (`Support\Composer::vendorPath()` resolves the actual install)
- copy: `Methods/{BuilderHelper,ModelFactoryMethodsClassReflectionExtension}.php`
- merge into moved forwarding extensions: `Methods/{EloquentBuilderForwardsCallsExtension,ModelForwardsCallsExtension,RelationForwardsCallsExtension}.php`
- copy: `Properties/*` except the ported `HigherOrderCollectionProxyPropertyExtension` (16 files, including `Schema/MySqlDataTypeToPhpTypeConverter.php`)
- copy: `Reflection/{AnnotationScopeMethodParameterReflection,AnnotationScopeMethodReflection,DynamicWhereParameterReflection,EloquentBuilderMethodReflection}.php`
- merge into moved scope reflections: `Reflection/ModelScopeMethodReflection.php`
- copy: `ReturnTypes/{AppFacadeEnvironmentReturnTypeExtension,EloquentBuilderExtension,FactoryDynamicMethodReturnTypeExtension,FormRequestSafeDynamicMethodReturnTypeExtension,ModelDynamicStaticMethodReturnTypeExtension,ModelFactoryDynamicStaticMethodReturnTypeExtension,ModelOnlyDynamicMethodReturnTypeExtension,ModelSerializationDynamicMethodReturnTypeExtension,RelationCollectionExtension,RequestFileExtension,RequestRouteExtension,StorageDynamicStaticMethodReturnTypeExtension,TestCaseExtension}.php`
- copy with the Eloquent collection port: `ReturnTypes/EloquentCollectionMapDynamicReturnTypeExtension.php`, moving `common/EloquentCollection.stub`'s `map()`/`mapWithKeys()` conditionals into the Eloquent `Collection` source
- copy: `Rules/*` except below and the ported `ArrKeyExistenceRule`, auth request-scope, config, console, `NoEnvCallsOutsideOfConfigRule`, `NoMissingTranslationsRule`, `NoUnnecessaryEnumerableToArrayCallsRule`, `UnusedViewsRule` and `UselessConstructs/` rules (including `Queue/`)
- omit: `Rules/DeferrableServiceProviderMissingProvidesRule.php`, `Rules/OctaneCompatibilityRule.php`
- copy: `SQL/*` (7 files)
- copy: `Types/*` except the ported `ArrPullParameterOutTypeExtension` and `ViewString*` files (12 files)

## New Hypervel source

- add: `Rules/Coroutine/` cancellation, worker-lifetime, static-state, unbounded-cache, runtime-config, transaction-coroutine and raw-sleep rules
- add: `Rules/` legacy model scope and legacy model property rules; `Rules/Queue/NoLegacyQueuePropertiesRule.php`
- add: `Rules/Validation/` pipe-separated validation rule
- add: PostgreSQL and SQLite schema parser adapters under `SQL/`

## stubs

- copy: `common/*` except below, the ported view stubs, `Foundation/Console/Kernel.stub` (its `command()` closure type moved to source; the stub keeps only the empty declaration the `Artisan` mixin needs for stub validation) and the stubs whose types are now in source: `Collection` (except below), `Conditionable`, `Enumerable`, `EnumeratesValues`, `HigherOrderProxies` and `Support/{Arr,Benchmark,Reflector,Str,Tappable}` (60 files)
- merge into `common/Helpers.stub` with their topics: the `cookie()`, `logger()`, `logs()`, `redirect()`, `request()`, `session()` and `url()` helper entries, whose conditional returns the source already declares (only `view()` is ported; `optional()` is typed in source and `OptionalExtension`; the stub records the omitted helpers)
- merge with their topic flows: the upstream typing for the declaration-only stubs that `common/Facades.stub`'s mixins need for stub validation: Events `dispatch()`/`until()` conditionals, `Connection::getQueryLog()` shape, Schema `getColumnListing()`, the `Request::header()` conditional, and the Cache `Repository` `ArrayAccess` implements (check Hypervel's enum keys)
- add to source with the routing stubs: upstream `common/Route.stub`'s `methods()` and `middleware()` element types as `Route` PHPDoc in `src/routing`, with a `types/` fixture (the stub keeps only the empty declaration stub validation needs)
- omit: `common/Redis/{PredisConnection,PredisFacade}.stub`, `common/Gate.stub` (Hypervel's `Gate::forUser()` and its contract already return `static`)
- add to source: upstream `common/MailMessage.stub`'s property shapes (`$view`, `$from`, `$replyTo`, `$attachments`, `$rawAttachments`) as `MailMessage` PHPDoc in `src/notifications`, with a `types/` fixture
- omit: `common/Contracts/Translation.stub` (only upstream's omitted `trans()` helper stub references it)
- merge into `common/`: `13.3.0/{EloquentBuilder,Model}.stub`, `13.35.0/{BelongsToMany,HasOneOrMany}.stub`, `13.37.0/HasOneOrManyThrough.stub`

## tests

- omit: `laravel-test.sh`
- copy: the remaining `application/` fixture app (app, config, factories, migrations, schema dump)
- merge into `Integration/IntegrationTest.php`: the remaining upstream entries with `Integration/data/*` (46 files), switching its config to `Type/data/config-check-model-properties.neon` with model properties
- copy: `Rules/*Test.php` except below and the ported `ArrKeyExistence`, auth, config, console, environment, enumerable `toArray()`, view, translation and useless-construct rule tests, the remaining `Rules/data/*`, `Rules/Queue/data/*`
- omit: `Rules/{DeferrableServiceProviderMissingProvidesRuleTest,OctaneCompatibilityRuleTest}.php`, `Rules/data/{CorrectDeferrableProvider,CorrectDeferrableProviderIndirect,IncorrectDeferrableProvider}.php`, `Rules/data/octane.neon`, `Rules/data/ContainerInjection.php`
- copy: `Type/*Test.php` except below, the ported collection and config tests, `GeneralTypeTest.php` and `MethodsClassReflectionExtensionTest.php`, with the remaining `Type/data/*`
- merge into `Type/BleedingEdgeTypeTest.php`: `bleeding-edge/collection-usage-inference.php` with the model-properties port (its `push($user->name)` case needs migration properties), recording its `contains()`/`partition()`/`some()`/`reject()` value-widening cases as `REMOVED:` like `collection-keys.php`'s mutation cases; `bleeding-edge/closure-usage-inference.php` (relation callbacks) and `bleeding-edge/eloquent-collection-map.php` with the Eloquent ports
- merge into `Type/CollectionDynamicReturnTypeExtensionsTest.php` with the Eloquent collection port, switching it to `config-with-migrations.neon` (they need model properties and the custom collections): `collection-generic-static-methods{,-l12}.php`, `eloquent-collection-map.php`
- merge into `Type/CollectionDynamicReturnTypeExtensionTest.php` with the model fixtures: `collection-filter.php`, `collection-reject.php`, `collection-where.php` (they need `accounts()->active()->get()` to return `AccountCollection`)
- omit: `Type/data/collection-generic-static-methods-l11.php` (Laravel 11's Eloquent `partition()` returned `static`; Hypervel follows the Laravel 12 base collection)
- merge: the remaining `Type/GeneralTypeTest.php` data entries, and its `data/config-with-migrations.neon` configuration once migrations are ported; `higher-order-collection-proxy-methods.php` needs model properties and Hypervel's proxy results (`avg` is `float|int|null`, `max`/`min` include `null`)
- merge: `Type/{RedisDriverNullTypeTest,RedisDriverPhpRedisTypeTest}.php` and their data into one phpredis test, with the Redis stubs; recheck `Type/data/facades.php`'s Redis expectations, which reflect the current source types; omit `Type/RedisDriverPredisTypeTest.php` with `redis-driver-predis.*`
- copy: `Unit/*Test.php` except `Support/{BootstrapErrorHandlerTest,RecursiveDirectoryIteratorTest}.php` (11 classes, including `SQL/` and `Support/`), `Unit/data/*`
