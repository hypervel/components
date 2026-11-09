# Hypervel PHPStan remaining-file checklist

Working checklist for [2026-11-04-hypervel-phpstan.md](2026-11-04-hypervel-phpstan.md). Remove entries once handled. Paths are relative to `/home/binaryfire/workspace/references/larastan` unless they start with `src/` or `tests/` in this repository.

Actions: **copy** (cp, read, adapt), **move** (mv existing Hypervel file), **merge** (combine into the named file), **add** (new Hypervel file), **omit** (record per the porting policy).

Destinations: package root `src/phpstan/`; upstream `src/` -> `src/phpstan/src/`; `stubs/` -> `src/phpstan/stubs/`; `tests/` -> `tests/PHPStan/`.

## Package root

- `extension.neon` services: omit the container ArrayAccess entries
- `extension.neon` and Components integration: set `reportIgnoresWithoutComments: true`; add `phpstan-deprecation-rules` includes to `phpstan.neon.dist`/`phpstan.types.neon.dist`
- omit: `phpcs.xml.dist`, `phpstan.neon.dist`, `phpstan-baseline.neon`, `phpunit.xml.dist`, `phpunit-bootstrap.php`, `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md`, `RELEASE.md`, `UPGRADE.md`, `assets/` (repository tooling; Components owns its own)
- merge into `src/docs/static-analysis.md`: `docs/custom-config-parameters.md`, `docs/custom-types.md`, `docs/errors-to-ignore.md`, `docs/features.md`, `docs/rules.md`
- omit: `e2e/` Laravel project baselines (replaced by the plan's real-project checks)

## Runtime prerequisites

- `src/bus/src/PendingChain.php`, `src/bus/src/Queueable.php`, `src/bus/src/ChainedBatch.php`, `src/support/src/Testing/Fakes/{QueueFake,PendingChainFake}.php` and their existing tests (chain/fake precedence)

## src

- omit: `Internal/ComposerHelper.php` (`Support\Composer::vendorPath()` resolves the actual install)
- omit: `Properties/ReflectionTypeContainer.php` (unused since upstream removed container ArrayAccess support)
- omit: `Rules/DeferrableServiceProviderMissingProvidesRule.php`, `Rules/OctaneCompatibilityRule.php`
- omit: `SQL/*` (`hypervel/schema-parser` replaces the parser boundary and its iamcal/phpMyAdmin adapters)

## New Hypervel source

- add: `Rules/Coroutine/` cancellation, worker-lifetime, static-state, unbounded-cache, runtime-config, transaction-coroutine and raw-sleep rules
- add: `Rules/` legacy model property rule; `Rules/Queue/NoLegacyQueuePropertiesRule.php`
- add: `Rules/Validation/` pipe-separated validation rule

## stubs

- copy with their topic flows: `common/{Attribute,Console/{Command,HasParameters},Contracts/{Cache,Container,Kernel,Mail,Queue,UrlGenerator},Cookie/SymfonyCookie,Database/Eloquent/Concerns/{HasAttributes,HasEvents,HasRelationships},Database/{ManagesTransactions,Migrations/Migrator},Events/Dispatcher,Http/RedirectResponse,Macroable,Notifications/Notification,Routing/{Redirector,UrlGenerator},Session/SessionManager,Support/{HtmlString,Manager,Optional},ThirdParty,Validation/Validator}.stub`, except below; `EloquentCollection`, `MorphTo`, `MorphToMany`, `Pagination` and `Contracts/Pagination` need no separate stub: the source declares their types, with `toQuery()`'s return-type extension and `MorphTo`'s map refinements in the combined `Database/Eloquent/Relations.stub`
- stubs repeat the source's native signatures except returns that name a collection or paginator, whose stubs fail stub validation (variance); `ModelPropertyColumnRule` checks the pagination and `pluck()` columns that upstream's stubs type
- merge into `common/Helpers.stub` with their topics: the `cookie()`, `logger()`, `logs()`, `redirect()`, `request()`, `session()` and `url()` helper entries, whose conditional returns the source already declares (only `view()` is ported; `optional()` is typed in source and `OptionalExtension`; the stub records the omitted helpers)
- merge with their topic flows: the upstream typing for the declaration-only stubs that `common/Facades.stub`'s mixins need for stub validation: Events `dispatch()`/`until()` conditionals, `Connection::getQueryLog()` shape, Schema `getColumnListing()`, and the Cache `Repository` `ArrayAccess` implements (check Hypervel's enum keys)
- add to source with the routing stubs: upstream `common/Route.stub`'s `methods()` and `middleware()` element types as `Route` PHPDoc in `src/routing`, with a `types/` fixture (the stub keeps only the empty declaration stub validation needs)
- omit: `common/{Http/InteractsWithInput,Foundation/Http/FormRequest,Support/ValidatedInput}.stub`; `common/Http/Request.stub` stays an empty declaration (the source declares the request getter, `safe()` and `validated()` types)
- omit: `common/Redis/*.stub` and `common/Contracts/Redis.stub` (the source tags, `MultiExec` and mixins type the pooled surface; `@mixin \Redis` would drop the cluster unions and expose connection-bound methods), `common/Gate.stub` (Hypervel's `Gate::forUser()` and its contract already return `static`)
- add to source: upstream `common/MailMessage.stub`'s property shapes (`$view`, `$from`, `$replyTo`, `$attachments`, `$rawAttachments`) as `MailMessage` PHPDoc in `src/notifications`, with a `types/` fixture
- omit: `common/Contracts/Translation.stub` (only upstream's omitted `trans()` helper stub references it)

## tests

- omit: `laravel-test.sh`
- omit: `application/app/{ModelWithConflictingInitializer.php,UserResource.php,Providers/}` (no upstream test or source references them)
- omit: `Rules/{DeferrableServiceProviderMissingProvidesRuleTest,OctaneCompatibilityRuleTest}.php`, `Rules/data/{CorrectDeferrableProvider,CorrectDeferrableProviderIndirect,IncorrectDeferrableProvider}.php`, `Rules/data/octane.neon`, `Rules/data/ContainerInjection.php`
- copy: `Type/*Test.php` except below, the ported collection, config and model-property tests, `GeneralTypeTest.php` and `MethodsClassReflectionExtensionTest.php`, with the remaining `Type/data/*`
- merge: the remaining `Type/GeneralTypeTest.php` data entries (its `phpstan-tests.neon` already matches upstream's `config-with-migrations.neon`)
- omit: `Unit/SQL/*` (parser factory and phpMyAdmin adapter tests; `hypervel/schema-parser` has its own)
