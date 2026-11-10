# Hypervel PHPStan remaining-file checklist

Working checklist for [2026-11-04-hypervel-phpstan.md](2026-11-04-hypervel-phpstan.md). Remove entries once handled. Paths are relative to `/home/binaryfire/workspace/references/larastan` unless they start with `src/` or `tests/` in this repository.

Actions: **copy** (cp, read, adapt), **move** (mv existing Hypervel file), **merge** (combine into the named file), **add** (new Hypervel file), **omit** (record per the porting policy).

Destinations: package root `src/phpstan/`; upstream `src/` -> `src/phpstan/src/`; `stubs/` -> `src/phpstan/stubs/`; `tests/` -> `tests/PHPStan/`.

## Package root

- omit: `phpcs.xml.dist`, `phpstan.neon.dist`, `phpstan-baseline.neon`, `phpunit.xml.dist`, `phpunit-bootstrap.php`, `AGENTS.md`, `CLAUDE.md`, `CONTRIBUTING.md`, `RELEASE.md`, `UPGRADE.md`, `assets/` (repository tooling; Components owns its own)
- omit: `docs/errors-to-ignore.md` (higher-order messages on support collections are typed, and NEON pattern ignores are not recommended)
- omit: `e2e/` Laravel project baselines (replaced by the plan's real-project checks)

## src

- omit: `Internal/ComposerHelper.php` (`Support\Composer::vendorPath()` resolves the actual install)
- omit: `Properties/ReflectionTypeContainer.php` (unused since upstream removed container ArrayAccess support)
- omit: `Rules/DeferrableServiceProviderMissingProvidesRule.php`, `Rules/OctaneCompatibilityRule.php`
- omit: `SQL/*` (`hypervel/schema-parser` replaces the parser boundary and its iamcal/phpMyAdmin adapters)

## stubs

- omit, because the source declares their types: `common/{Attribute,Console/{Command,HasParameters},Contracts/{Events/Dispatcher,Kernel},Database/Eloquent/Concerns/{HasAttributes,HasEvents,HasRelationships},Database/{ManagesTransactions,Migrations/Migrator},Events/Dispatcher,Macroable,Notifications/Notification,Routing/UrlGenerator,Validation/Validator}.stub`, the `cookie()`, `logger()`, `logs()`, `redirect()`, `request()`, `session()` and `url()` entries of `common/Helpers.stub`, and the `common/{MailMessage,Route}.stub` properties and methods (now PHPDoc in `src/notifications` and `src/routing`); `EloquentCollection`, `MorphTo`, `MorphToMany`, `Pagination` and `Contracts/Pagination` likewise, with `toQuery()`'s return-type extension and `MorphTo`'s map refinements in the combined `Database/Eloquent/Relations.stub`
- omit: `common/{Contracts/{Cache,Container,Mail,Queue,UrlGenerator},Cookie/SymfonyCookie,Http/RedirectResponse,Routing/Redirector,Session/SessionManager,Support/{HtmlString,Manager,Optional},ThirdParty}.stub` (declarations for upstream's other stubs; no Hypervel stub references them)
- omit: `common/{Http/InteractsWithInput,Foundation/Http/FormRequest,Support/ValidatedInput}.stub`; `common/Http/Request.stub` stays an empty declaration (the source declares the request getter, `safe()` and `validated()` types)
- omit: `common/Redis/*.stub` and `common/Contracts/Redis.stub` (the source tags, `MultiExec` and mixins type the pooled surface; `@mixin \Redis` would drop the cluster unions and expose connection-bound methods), `common/Gate.stub` (Hypervel's `Gate::forUser()` and its contract already return `static`)
- omit: `common/Contracts/Translation.stub` (only upstream's omitted `trans()` helper stub references it)

## tests

- omit: `laravel-test.sh`
- omit: `application/app/{ModelWithConflictingInitializer.php,UserResource.php,Providers/}` (no upstream test or source references them)
- omit: `Rules/{DeferrableServiceProviderMissingProvidesRuleTest,OctaneCompatibilityRuleTest}.php`, `Rules/data/{CorrectDeferrableProvider,CorrectDeferrableProviderIndirect,IncorrectDeferrableProvider}.php`, `Rules/data/octane.neon`, `Rules/data/ContainerInjection.php`
- omit: `Type/RedisDriver{Null,Predis}TypeTest.php` with their data (the `redisDriver` setting is omitted), `Type/data/logger.php` (never registered upstream) and `Type/data/config-with-migrations*.neon` (`phpstan-tests.neon` matches them)
- omit: `Unit/SQL/*` (parser factory and phpMyAdmin adapter tests; `hypervel/schema-parser` has its own)
