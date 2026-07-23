# Make CarbonImmutable the Framework Default

## Status

Implementation plan for the framework-wide date audit. The source audit, runtime probes, performance investigation, and second-opinion design loop are complete. This document records the settled implementation, the complete migration ledger, and the verification needed to leave the repository as though immutable dates had been the original design.

Backward compatibility with earlier Hypervel versions, minimizing churn, and preserving the current mutable default are not constraints. Laravel-compatible public APIs remain the reference where they do not conflict with this approved Hypervel modernization. The design deliberately avoids a new clock abstraction, raw timestamp rewrites, compatibility shims, duplicate date helpers, or permanent audit tooling.

## Scope

Make `Hypervel\Support\CarbonImmutable` the canonical framework date value and the default returned by the date factory, facade, helpers, Eloquent date casts, request casts, data objects, test-time helpers, and the PSR clock. Retain `Hypervel\Support\Carbon` as the explicit mutable opt-out:

```php
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Date;

Date::use(Carbon::class);
```

Complete the change across the whole components repository:

- require Carbon `^3.13.1` in the monorepo and every split package that depends on it;
- add the immutable Hypervel class without duplicating Hypervel's Carbon additions;
- ensure mutable/immutable conversions stay inside the Hypervel class pair;
- tighten the date-factory handlers to the contracts the framework already advertises;
- canonicalize framework-owned immutable values that currently use Carbon's base class;
- convert remaining framework-owned mutable direct construction deliberately;
- fix every mutation or native type that is invalid under an immutable default;
- make DataObject hydration honor both the declared property type and the configured date factory;
- make the existing PSR clock return the canonical Hypervel class;
- update test-time APIs, static teardown, public metadata, application docs, contributor policy, upgrade guidance, and the Laravel-differences record;
- delete superseded fixtures, comments, documentation, todo text, and redundant cleanup.

Private packages under `packages/hypervel` and `packages/hypervel-dev` are outside this framework worktree and require no migration in this work unit. Their live source has no Carbon usage requiring conversion; the one native `DateTimeImmutable` value found in `SecurityEvent` is already correct. Future private-package work follows the same convention added to `AGENTS.md`.

## Desired final architecture

| Boundary | Final date type and construction rule |
|---|---|
| `Date`, `now()`, `today()`, Eloquent `date` / `datetime`, request casts | `CarbonInterface` contract; exact `Hypervel\Support\CarbonImmutable` by default |
| Explicit application opt-out | `Date::use(Hypervel\Support\Carbon::class)`; factory-routed APIs then return the mutable Hypervel class |
| Framework-owned internal, cached, property-held, or shared timestamps | Direct `Hypervel\Support\CarbonImmutable`; unaffected by an application's mutable opt-out |
| Explicit Eloquent `immutable_date` / `immutable_datetime` casts | `Hypervel\Support\CarbonImmutable`, including while the general factory is configured mutable |
| Concrete mutable conversions and mutable-input serialization | `Hypervel\Support\Carbon` only where mutability is intentional and named |
| User-configurable public date values | `CarbonInterface`; creation routes through `Date` |
| Native and third-party boundaries | `DateTimeInterface`, or the exact native/base Carbon class when that declared target is the point of the API |
| PSR-20 clock | Existing `Carbon\FactoryImmutable`, configured to create `Hypervel\Support\CarbonImmutable` |
| Elapsed-time mechanisms already using monotonic/native time | Leave `hrtime()`, `microtime()`, and integer timestamps unchanged |

Immutability is a value-semantics choice, not permission to replace Carbon with raw PHP primitives. Date modifiers continue to use Carbon; code captures the returned value whenever it must be retained.

## Audit method and completeness baseline

The pre-plan audit did not rely on the factory flip or PHPStan to discover scope. It built the union of:

- every mutable, immutable, and interface Carbon import/reference across `src/` and `tests/`;
- every `Date` facade import and `now()` / `today()` helper call;
- every direct `Carbon::now()`, parse/create/instance call, and `new Carbon` construction;
- concrete `Carbon` / `DateTime` properties, parameters, return types, facade metadata, and model property docs;
- date modifier calls, including multiline/discarded-return forms;
- date values stored in properties, statics, coroutine context, arrays, caches, batches, and worker-lifetime services;
- active Boost documentation examples and contributor/AI/upgrade documentation.

Every mutation-risk file was read in context. The complete direct-construction ledger is recorded below so implementation has a per-file checklist. The final verification repeats the broad searches over the entire trees and reviews the complete diff file by file; PHPStan and the test suites are safety nets, not substitutes for the manual audit.

## Findings and fixed decisions

| Finding | Evidence | Decision |
|---|---|---|
| Factory and direct construction are independent surfaces | `DateFactory::DEFAULT_CLASS_NAME` affects `Date` and helpers, but not `Hypervel\Support\Carbon::now()` | Flip the factory and separately migrate every direct site |
| The declared Carbon floor must match the audited dependency | The local development install uses 3.13.1, while the root and split-package manifests still allowed 3.8.4 and the repository has no lowest-dependency test suite | Require `^3.13.1` everywhere rather than claiming support for an untested older patch |
| Hypervel needs its own immutable class | The mutable subclass adds Conditionable, Dumpable, `createFromId`, `plus`, and `minus`; base immutable lacks them | Add `Hypervel\Support\CarbonImmutable` and share only those additions through one trait |
| Carbon conversions lose Hypervel behavior today | Carbon 3.13.1's `Mutability` trait casts to `Carbon\Carbon` / `Carbon\CarbonImmutable`; runtime probes confirm the subclass is dropped | Override both directions on the Hypervel pair |
| Carbon's immutable magic modifier metadata erases subclasses in static analysis | The base immutable class hardcodes `CarbonImmutable` for seven aliases used across exact Hypervel-class boundaries, although runtime probes confirm every operation preserves the subclass | Override those seven inherited magic annotations with `static` on the Hypervel immutable owner; leave unlisted parent metadata visible so future exact-boundary gaps fail visibly |
| Carbon's test state is already shared | Carbon 3.13.1 routes both classes through `FactoryImmutable::getDefaultInstance()`; runtime probes verified test time, macros, serializer, and to-string state | Delete the dual-set override and duplicate immutable reset; keep one authoritative cleanup path |
| Current class handlers violate the advertised date contract | `DateFactory::use(DateTime::class)` makes `now(): CarbonInterface` and the global helper throw native return `TypeError`s | Restrict class handlers to `class-string<CarbonInterface>`; keep callable and Carbon `Factory` escape hatches |
| DateFactory's generic constructor fallback is dead | Installed `CarbonInterface` declares `instance(DateTimeInterface): static` | Delete the `new $dateClass(...)` fallback and its non-Carbon fixture |
| Facade magic-method type resolution borrowed constructor context | DateFactory has no constructor, so the documenter emitted its imported `Closure` name verbatim into another namespace; inherited constructors also resolve imports against the parent file | Resolve class-level `@method` types through a class-backed context and fail loudly on parser/resolution defects instead of emitting namespace-unsafe verbatim metadata |
| Two lifecycle mutations discard an immutable return | HTTP and Console kernels call `setTimezone()` without assignment | Reassign the coroutine-local/property value before handlers run |
| Scheduler mutates its mutex input under mutable opt-out | `repeatEvents()` calls `endOfMinute()` on stored `startedAt`, then passes it to `serverShouldRun()` | Port Laravel 13.x's separate copied end-of-minute boundary and inner expiry check |
| Four native return declarations reject immutable values | Two validation parsing methods and two queued `retryUntil()` wrappers declare `?DateTime` | Use `?DateTimeInterface` and add regressions |
| Full-suite verification exposed a protobuf first-registration race | Concurrent gRPC client fixtures corrupted Google Protobuf's process-global descriptor pool; the server also first constructed request messages inside request coroutines | Prewarm server request and eligible declared response messages before workers fork, prewarm the client isolation fixture before parallel work, and document remaining application-owned message classes |
| DataObject is neither factory-aware nor target-aware | Exact handler/serializer maps omit native/base/Hypervel immutable targets and always hydrate mutable Hypervel Carbon | Normalize through `Date`, then cast to the declared target; serialize all date targets through their common interface |
| Carbon `instance()` preserves same-mutability subclasses | A configured Hypervel subclass leaks through direct `Carbon::instance()` / `CarbonImmutable::instance()` calls, violating DataObject's exact concrete-target contract | Cross to the opposite mutability before all four concrete Carbon conversions so `instance()` constructs the exact target while retaining Carbon settings |
| Existing immutable subsystems use the base class | Bus, Horizon, gRPC, maintenance caching, batch fakes, and Testbench already choose immutability | Canonicalize them to the Hypervel immutable class rather than preserving two framework-owned immutable types |
| Ordinary Eloquent date reads are not an aliasing defect | Built-in date casts construct a fresh value on each read and do not use the class-cast cache | Do not justify the change with a false model-aliasing claim; focus on value semantics and held/shared values |

## Backing research

### Required Carbon behavior

The repository requires Carbon `^3.13.1`. The audited development install is `3.13.1` (`2937ad3d1d2c506fd2bc97d571438a95641f44e2`). The load-bearing APIs are:

```php
// Carbon\Traits\Cast
/** @template T
 *  @param class-string<T> $className
 *  @return T
 */
public function cast(string $className): mixed;
```

```php
// Carbon\CarbonInterface
public static function instance(DateTimeInterface $date): static;
```

```php
// Carbon\Factory
public function __construct(array $settings = [], ?string $className = null);
```

Runtime probes confirmed:

- current `Hypervel\Support\Carbon::toMutable()` returns base `Carbon\Carbon`;
- current `Hypervel\Support\Carbon::toImmutable()` returns base `Carbon\CarbonImmutable`;
- `Carbon\CarbonImmutable::toImmutable()` returns the same object;
- mutable `setTestNow()` controls base and subclass immutable clocks;
- macros, serialization callbacks, and the static to-string format registered through mutable Carbon are visible to immutable Carbon and cleared by the mutable base reset methods.

These facts permit the small class pair and single cleanup owner below. Do not add macro synchronization, a second test clock, or per-class teardown registries.

### Current Laravel reference

The local reference is the monorepo checkout at `examples/laravel/framework` (`../../../examples/laravel/framework` from this worktree), branch `13.x`, commit `23e9e71f382b91510c70b5b6f9ae0776f1b88e12` (2026-07-21). Relevant current source:

- `src/Illuminate/Support/DateFactory.php` remains mutable by default and retains Laravel's broad compatibility paths;
- `src/Illuminate/Console/Scheduling/ScheduleRunCommand.php` computes `$endOfMinute = $this->startedAt->copy()->endOfMinute()` once and stops inside the event loop when `Date::now()->gt($endOfMinute)`;
- the Foundation HTTP and Console kernels still discard `setTimezone()` because Laravel's direct Carbon remains mutable;
- Validation's two parsing methods and the Mail/Notifications retry wrappers remain untyped with mutable `DateTime` docblocks.

Hypervel ports the scheduler's structural correction, then applies its approved immutable-default and modern native typing adaptations. It does not copy Laravel's mutable default or retain a broken handler solely for 1:1 shape.

### Performance conclusion

Pre-plan profiles measured the mechanism that changes: immutable modifier allocation. Construction/factory dispatch was neutral; Eloquent cast overhead was approximately `0.14µs`; a single modifier added approximately `1.16µs`; three modifiers approximately `1.65µs`; same-worker HTTP profiles were about `0.8–1%`, with an artificial ten-modifier request about `3.3%`; memory impact was negligible. Bus, Horizon, and gRPC already pay immutable modifier costs.

The implementation adds no unmeasured hot-path mechanism: the Hypervel subclass has no per-instance wrapper, the DateFactory dispatch removes a branch, and DataObject target dispatch is not a per-request framework loop. Functional, static, structural, and full-suite verification are the completion bar. Do not add a branch benchmark, threshold, CI job, or raw timestamp micro-optimization.

### Protobuf descriptor race exposed by full-suite verification

The final ParaTest run exposed Google Protobuf's unsynchronized generated `initOnce()` path when several gRPC client coroutines constructed the same message class for the first time. The isolated test then passed because its descriptor pool was already warm, but the stack trace proved a real process-global registration race. The supported server path had the same defect: `GrpcRouter::compileAndWarm()` reflected request classes before fork without constructing them, while `MessageSerializer::deserialize()` performed the first construction inside concurrent request coroutines.

Warm every validated server request class during the router's existing boot-only, pre-fork compilation pass. Also warm a declared response type when it is a named, concrete protobuf `Message` subclass whose constructor has no required parameters:

```php
// Generated descriptor registration is process-global and not coroutine-safe.
// Construct known messages before workers fork and requests can run concurrently.
$requestReflection->newInstance();

$responseType = $requestParameter->getDeclaringFunction()->getReturnType();

if ($responseType instanceof ReflectionNamedType
    && ($responseClass = $this->parameterClassName($requestParameter, $responseType)) !== null
    && is_subclass_of($responseClass, Message::class)) {
    $responseReflection = new ReflectionClass($responseClass);

    if ($responseReflection->isInstantiable()
        && ($responseReflection->getConstructor()?->getNumberOfRequiredParameters() ?? 0) === 0) {
        $responseReflection->newInstance();
    }
}
```

The zero-required-parameters guard matters only for responses: Hypervel already constructs every request without arguments, but it serializes the response instance supplied by the handler and must not reject a response class whose handler supplies constructor arguments. A separate-process router regression starts with two independent cold metadata owners and proves request plus eligible response initialization. The existing client isolation test constructs one request before entering `parallel()` because its contract is call isolation, not dependency bootstrap. User documentation tells applications to construct any other message class that may first initialize concurrently, including client messages and untyped, union, or iterable server responses. Do not add a descriptor registry, lock, generic preloader, generated fixture, or todo entry.

## Implementation order

Implement lower owners before consumers:

1. add the Hypervel immutable class and shared date helpers;
2. make DateFactory and the Date facade honestly immutable by default;
3. configure the existing PSR clock and simplify Carbon test-state cleanup;
4. make DataObject hydration and serialization target-aware;
5. fix the kernels, scheduler, validation, and queued retry native contracts;
6. migrate direct mutable construction and existing base-immutable framework values using the ledgers;
7. update test-time APIs, public/model types, facade metadata, tests, and fixtures;
8. update active documentation, contributor policy, differences, release/upgrade guidance, and remove the todo;
9. perform the final structural sweep and complete validation.

## 1. Add the canonical Hypervel class pair

### Files

- new `src/support/src/Traits/DateHelpers.php`
- `src/support/src/Carbon.php`
- new `src/support/src/CarbonImmutable.php`
- `tests/Support/SupportCarbonTest.php`
- new `tests/Support/SupportCarbonImmutableTest.php`

### Shared behavior

Move only the Hypervel additions shared by both date classes into the existing Support `Traits/` convention:

```php
namespace Hypervel\Support\Traits;

use InvalidArgumentException;
use Symfony\Component\Uid\TimeBasedUidInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

trait DateHelpers
{
    use Conditionable;
    use Dumpable;

    public static function createFromId(Uuid|Ulid|string $id): static
    {
        if (is_string($id)) {
            $id = Ulid::isValid($id) ? Ulid::fromString($id) : Uuid::fromString($id);
        }

        if (! $id instanceof TimeBasedUidInterface) {
            throw new InvalidArgumentException(
                'The given UUID is not time-based and cannot be converted to a date.'
            );
        }

        return static::createFromInterface($id->getDateTime());
    }

    public function plus(
        int $years = 0,
        int $months = 0,
        int $weeks = 0,
        int $days = 0,
        int $hours = 0,
        int $minutes = 0,
        int $seconds = 0,
        int $microseconds = 0
    ): static
    {
        return $this->add("
            {$years} years {$months} months {$weeks} weeks {$days} days
            {$hours} hours {$minutes} minutes {$seconds} seconds {$microseconds} microseconds
        ");
    }

    public function minus(
        int $years = 0,
        int $months = 0,
        int $weeks = 0,
        int $days = 0,
        int $hours = 0,
        int $minutes = 0,
        int $seconds = 0,
        int $microseconds = 0
    ): static
    {
        return $this->sub("
            {$years} years {$months} months {$weeks} weeks {$days} days
            {$hours} hours {$minutes} minutes {$seconds} seconds {$microseconds} microseconds
        ");
    }
}
```

Preserve these signatures and interval strings exactly when moving them.

Do not put conversion methods or test-clock workarounds in the trait. Conversion direction differs by class, and Carbon 3.13.1 already shares test state.

### Conversion-preserving classes

```php
namespace Hypervel\Support;

use Carbon\Carbon as BaseCarbon;
use Hypervel\Support\Traits\DateHelpers;

class Carbon extends BaseCarbon
{
    use DateHelpers;

    public function toMutable(): static
    {
        return $this->cast(static::class);
    }

    public function toImmutable(): CarbonImmutable
    {
        return $this->cast(CarbonImmutable::class);
    }
}
```

```php
namespace Hypervel\Support;

use Carbon\CarbonImmutable as BaseCarbonImmutable;
use Hypervel\Support\Traits\DateHelpers;

/**
 * Carbon's immutable magic modifier metadata names its base class even though
 * these methods preserve subclasses at runtime.
 *
 * @method static addMicroseconds(int|float $value = 1)
 * @method static addMinute()
 * @method static addSecond()
 * @method static addSeconds(int|float $value = 1)
 * @method static ceilSeconds(float $precision = 1)
 * @method static subMinutes(int|float $value = 1)
 * @method static subSeconds(int|float $value = 1)
 */
class CarbonImmutable extends BaseCarbonImmutable
{
    use DateHelpers;

    public function toMutable(): Carbon
    {
        return $this->cast(Carbon::class);
    }

    public function toImmutable(): static
    {
        return $this;
    }
}
```

The mutable-to-mutable conversion preserves Carbon's copy behavior while keeping late-static subclasses. Immutable-to-immutable preserves Carbon's identity behavior. Do not build a hierarchy between the mutable and immutable classes, a strategy object, or duplicated helper implementations.

### Tests

Test exact classes rather than only base-class `instanceof` checks:

- direct construction, parse, serialization/unserialization, conditionable, and dumpable behavior on each class;
- `createFromId` with time-based UUID, ULID, string input, and invalid non-time-based UUID;
- all named `plus` / `minus` units and proof that immutable operations leave the original unchanged;
- mutable -> mutable, mutable -> immutable, immutable -> mutable, and immutable -> immutable conversions;
- subclasses of each Hypervel class retain late-static behavior where the API promises `static`;
- conversions preserve instant, microseconds, timezone, locale/settings, and the Hypervel helper surface.

## 2. Make DateFactory honest and immutable by default

### Files

- `src/support/src/DateFactory.php`
- `src/support/src/Facades/Date.php`
- `src/facade-documenter/facade.php`
- `src/support/src/functions.php`
- `src/support/src/Stringable.php`
- `src/foundation/src/helpers.php`
- `tests/FacadeDocumenter/ClassDocblockResolutionTest.php`
- `tests/Support/DateFacadeTest.php`
- `tests/Support/SupportStringableTest.php`
- delete `tests/Support/Fixtures/CustomDateClass.php`
- update factory/cast expectations in `tests/Database/Eloquent/Concerns/DateFactoryTest.php`

### State and handler types

Use native types plus class-string PHPDoc where PHP cannot express it:

```php
use Carbon\CarbonInterface;
use Carbon\Factory;
use Closure;
use ReflectionClass;

/** @var class-string<CarbonInterface> */
public const string DEFAULT_CLASS_NAME = CarbonImmutable::class;

/** @var class-string<CarbonInterface>|null */
protected static ?string $dateClass = null;

protected static ?Closure $callable = null;

protected static ?Factory $factory = null;
```

Normalize callables once and validate class handlers at configuration time:

```php
public static function use(mixed $handler): void
{
    if ($handler instanceof Factory) {
        static::useFactory($handler);
        return;
    }

    if (is_string($handler) && is_a($handler, CarbonInterface::class, true)) {
        static::useClass($handler);
        return;
    }

    if (is_callable($handler)) {
        static::useCallable($handler);
        return;
    }

    throw new InvalidArgumentException(
        'Invalid date creation handler. Please provide a Carbon class, callable, or Carbon factory.'
    );
}

public static function useCallable(callable $callable): void
{
    static::$callable = Closure::fromCallable($callable);
    static::$dateClass = null;
    static::$factory = null;
}

/** @param class-string<CarbonInterface> $dateClass */
public static function useClass(string $dateClass): void
{
    if (
        ! is_a($dateClass, CarbonInterface::class, true)
        || ! (new ReflectionClass($dateClass))->isInstantiable()
    ) {
        throw new InvalidArgumentException(
            'The date class must be an instantiable CarbonInterface implementation.'
        );
    }

    static::$dateClass = $dateClass;
    static::$factory = null;
    static::$callable = null;
}

public static function useFactory(Factory $factory): void
{
    static::$factory = $factory;
    static::$dateClass = null;
    static::$callable = null;
}
```

This supports closure, invokable-object, callable-string, mutable Hypervel class, custom concrete Carbon subclass, `Factory`, and `FactoryImmutable` handlers. It deliberately rejects `DateTime::class`, `CarbonInterface::class`, abstract Carbon classes, non-Carbon wrappers with an `instance()` method, and arbitrary class strings. Reflection runs only when configuring the boot-time handler, not during date creation.

### Dispatch

Keep the three real handler modes and remove the impossible generic constructor fallback:

```php
public function __call(string $method, array $parameters): mixed
{
    $defaultClassName = static::DEFAULT_CLASS_NAME;

    if (static::$callable !== null) {
        return (static::$callable)($defaultClassName::$method(...$parameters));
    }

    if (static::$factory !== null) {
        return static::$factory->{$method}(...$parameters);
    }

    $dateClass = static::$dateClass ?? $defaultClassName;

    if (
        method_exists($dateClass, $method)
        || $dateClass::hasMacro($method)
    ) {
        return $dateClass::$method(...$parameters);
    }

    return $dateClass::instance($defaultClassName::$method(...$parameters));
}
```

Do not add a per-call `instanceof CarbonInterface` guard around callable output. The callable is the deliberate Laravel-style escape hatch; its supported return contract remains `CarbonInterface`, but validating every call adds hot-path machinery without strengthening the class/factory contract. It may transform the generated value into a different mutable or immutable Carbon implementation, not into an unrelated date type.

Remove the stale `RuntimeException` import/throws documentation if no code path explicitly uses it after this simplification.

### Public metadata

Change only date-producing `@method` returns in `DateFactory` from concrete mutable Carbon to `CarbonInterface` (including nullable forms): `create`, `createFromDate`, `createFromFormat`, `createFromIsoFormat`, `createFromLocaleFormat`, `createFromLocaleIsoFormat`, `createFromTime`, `createFromTimeString`, `createFromTimestamp`, `createFromTimestampMs`, `createFromTimestampMsUTC`, `createFromTimestampUTC`, `createMidnightDate`, `createSafe`, `createStrict`, `fromSerialized`, `getTestNow`, `instance`, `make`, `now`, `parse`, `parseFromLocale`, `rawCreateFromFormat`, `rawParse`, `today`, `tomorrow`, and `yesterday`. Do not mechanically change unrelated bool, scalar, array, interval, translator, or `mixed` methods. Update the real public method metadata to show `useFactory(Factory $factory)` and `useClass(class-string<CarbonInterface>)` in PHPDoc where native syntax cannot; the generated facade will retain native `string` for `useClass()` because the documenter reflects native parameter types.

Regenerate the Date facade from the corrected owner, then lint it:

```bash
php -f src/facade-documenter/facade.php -- Hypervel\\Support\\Facades\\Date
php -f src/facade-documenter/facade.php -- --lint Hypervel\\Support\\Facades\\Date
```

Class-level `@method` tags must be resolved against the class that owns the docblock. The facade documenter uses a minimal class-backed context for that path instead of reflecting a synthetic `__construct`: constructor-less owners otherwise emit namespace-unsafe metadata, while inherited constructors resolve imports from the parent file. Delete the old text fallback; parser or resolution defects must stop this boot-time development tool with a useful stack trace instead of silently committing verbatim types. Cover both constructor-less imported types and inherited-constructor import conflicts in `ClassDocblockResolutionTest`. DateFactory's `getTranslationMessageWith()` metadata uses its real imported `Closure` name, which the class-backed context resolves to the global class. When a facade imports a global class, render that class through the facade's short or aliased import in `@method` lines so generated output shares PHP-CS-Fixer's `global_namespace_import` fixed point; keep namespaced and unimported classes fully qualified. Require both left and right class-name boundaries so an imported global basename cannot corrupt a namespaced FQCN. Cover imported global, unimported global, imported namespaced, and colliding namespaced-basename cases in `ClassDocblockResolutionTest`.

Change the stale `@return \Hypervel\Support\Carbon` on `src/support/src/functions.php::now()` to `CarbonInterface`. Keep the already-correct native `CarbonInterface` returns on Foundation's global `now()` / `today()` helpers, and ensure their prose does not claim a concrete mutable result. Change `Stringable::toDate()` from `mixed` to `?CarbonInterface`; its parse branch is non-null while the formatted-creation branch can return null:

```php
public function toDate(
    ?string $format = null,
    ?string $tz = null
): ?CarbonInterface {
    return $format === null
        ? Date::parse($this->value, $tz)
        : Date::createFromFormat($format, $this->value, $tz);
}
```

Generated/default examples may describe the exact immutable class, but public contracts accepting configured handlers use the interface. Accept the facade documenter's removal of legacy methods that are no longer present on `DateFactory`; do not manually re-add stale `maxValue()` / `minValue()` entries after regeneration. Delete the Carbon 2-era `setWeekEndsAt()` / `setWeekStartsAt()` metadata, which neither Carbon 3 class nor its factory supports. Type `withTimeZone()` as returning `Carbon\Factory`; it is a factory-only API and the default Carbon class correctly rejects it.

### Tests

- default `Date::now()`, `Date::parse()`, `Date::today()`, global `now()`, and global `today()` are exact `Hypervel\Support\CarbonImmutable`;
- mutable opt-out returns exact `Hypervel\Support\Carbon` across those same routes;
- `Stringable::toDate()` returns exact immutable by default, exact mutable under opt-out, and null/throws according to the existing formatted/parse failure contracts;
- explicit class handlers for Hypervel and base Carbon mutable/immutable concrete classes are accepted and return the configured exact class;
- a named test-local class extending a Hypervel Carbon class is accepted and returned exactly; delete the old wrapper fixture instead of replacing it with another fixture file;
- closure, invokable object, callable string, mutable/immutable `Factory`, locale, macro, and flush-state behavior remain covered;
- `DateFactory::use()` and `useClass()` reject `DateTime::class`, `CarbonInterface::class`, an abstract Carbon subclass, the deleted wrapper shape, a non-Carbon class, and invalid scalar handlers with `InvalidArgumentException`;
- the callable escape hatch may deliberately transform the generated value into another `CarbonInterface`; do not retain the current test's unsupported native `DateTime` result;
- all tests use full native return types while touched.

## 3. Keep one clock and one Carbon static-state cleanup owner

### Files

- `src/foundation/src/Providers/FoundationServiceProvider.php`
- `tests/Foundation/Providers/FoundationServiceProviderTest.php`
- `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`
- `tests/Testing/PHPUnit/AfterEachTestSubscriberTest.php`
- `src/support/src/Carbon.php` (removal already covered)

### Existing PSR clock

Configure the already-bound PSR clock to construct the canonical subclass:

```php
use Carbon\FactoryImmutable;
use Hypervel\Support\CarbonImmutable;
use Psr\Clock\ClockInterface;

$this->app->singleton(
    ClockInterface::class,
    fn () => new FactoryImmutable(className: CarbonImmutable::class)
);
```

Tests assert exact `Hypervel\Support\CarbonImmutable`, the same frozen instant as `Date::now()` / `now()`, and continued immutability when `Date::use(Carbon::class)` opts the application factory into mutable dates.

Do not add a second Symfony container binding, call `Symfony\Component\Clock\Clock::set()`, or create a Hypervel clock wrapper. Carbon's `FactoryImmutable` already implements Symfony's clock interface, which extends PSR-20, while Foundation intentionally exposes the PSR key.

### Static teardown

Delete `Hypervel\Support\Carbon::setTestNow()`; the inherited Carbon 3.13.1 method already updates the shared default factory. Reduce the test subscriber to the single authoritative base reset:

```php
\Carbon\Carbon::resetMacros();
\Carbon\Carbon::resetToStringFormat();
\Carbon\Carbon::serializeUsing(null);
\Carbon\Carbon::useStrictMode();
\Carbon\Carbon::setTestNow();
```

Delete the redundant `\Carbon\CarbonImmutable::setTestNow()` call. Add one focused subscriber regression that registers/fixes state through the new Hypervel immutable class, invokes `flushFrameworkState()`, and proves mutable and immutable classes both return to real/default macro, serializer, string-format, strict-mode, and test-clock state. Do not duplicate a separate test for each reset line or add another cleanup registry.

## 4. Make DataObject date resolution target-aware

### Files

- `src/support/src/DataObject.php`
- `tests/Support/DataObjectTest.php`
- `src/boost/docs/data-objects.md`

### Supported declared targets

Hydrate all eight meaningful targets:

1. `DateTimeInterface` — configured DateFactory result;
2. `CarbonInterface` — configured DateFactory result;
3. native `DateTime` — exact native mutable value;
4. native `DateTimeImmutable` — exact native immutable value;
5. `Hypervel\Support\Carbon` — exact Hypervel mutable value;
6. `Hypervel\Support\CarbonImmutable` — exact Hypervel immutable value;
7. base `Carbon\Carbon` — exact base mutable value;
8. base `Carbon\CarbonImmutable` — exact base immutable value.

Interface targets follow `Date::use(...)`; concrete targets remain assignable and exact regardless of the current default. This is the declared-property contract, not a second global configuration system.

### Handler shape

Build one target-specific resolver per declared map key and stop treating falsy timestamps as null:

```php
protected static function getCustomizedDependencies(): array
{
    $dependencies = [];
    $dateTargets = [
        DateTimeInterface::class,
        CarbonInterface::class,
        DateTime::class,
        DateTimeImmutable::class,
        Carbon::class,
        CarbonImmutable::class,
        BaseCarbon::class,
        BaseCarbonImmutable::class,
    ];

    foreach ($dateTargets as $target) {
        $dependencies[$target] = static fn (mixed $value): ?DateTimeInterface =>
            $value === [] ? null : static::asDateTime($value, $target);
    }

    return $dependencies;
}
```

`replaceDependenciesData()` already skips null for nullable properties. For a non-nullable dependency it converts explicit null to the empty-array sentinel before invoking the handler so nested DataObjects can still hydrate from defaults. The date resolver must map that exact sentinel back to null, allowing the final constructor to raise its natural parameter `TypeError`; do not pass `[]` into Carbon. Do not retain the broader `$value ? ... : null`, which incorrectly maps timestamp `0` and string `'0'` to null. Keep this handling scoped to the date resolver rather than changing nested-DataObject null hydration.

Normalize every non-null input once through the configured Date factory, then cast by target:

```php
/** @param DateTimeInterface::class|CarbonInterface::class|DateTime::class|DateTimeImmutable::class|Carbon::class|CarbonImmutable::class|BaseCarbon::class|BaseCarbonImmutable::class $target */
protected static function asDateTime(mixed $value, string $target): DateTimeInterface
{
    if ($value instanceof DateTimeInterface) {
        $date = Date::instance($value);
    } elseif (is_numeric($value)) {
        $date = Date::createFromTimestamp(
            $value,
            date_default_timezone_get()
        );
    } elseif (static::isStandardDateFormat($value)) {
        $date = Date::parse($value)->startOfDay();
    } else {
        try {
            $date = Date::createFromFormat(static::$dateFormat, $value);
            // @phpstan-ignore catch.neverThrown (the Date facade's magic dispatch hides Carbon's @throws from analysis)
        } catch (InvalidFormatException) {
            $date = null;
        }

        $date ??= Date::parse($value);
    }

    return match ($target) {
        DateTimeInterface::class, CarbonInterface::class => $date,
        DateTime::class => DateTime::createFromInterface($date),
        DateTimeImmutable::class => DateTimeImmutable::createFromInterface($date),
        // instance() clones same-mutability subclasses, so cross the mutability
        // boundary first to honor the exact target while retaining Carbon settings.
        Carbon::class => Carbon::instance($date->toImmutable()),
        CarbonImmutable::class => CarbonImmutable::instance($date->toMutable()),
        BaseCarbon::class => BaseCarbon::instance($date->toImmutable()),
        BaseCarbonImmutable::class => BaseCarbonImmutable::instance($date->toMutable()),
    };
}
```

Keep the exact eight-class PHPDoc and explicit match: eight public target semantics are clearer than reflective method probing. Building the handlers from one local target list lets PHPStan 2.2.5 infer the captured literal union; local Closure PHPDocs do not narrow closure parameters in that version. Do not add a class-level registry, runtime default guard, converter objects, or per-target classes.

### Serialization

All eight values implement `DateTimeInterface` and share the same ISO serialization. Replace the exact-class date map with one interface serializer while preserving exact custom serializers for non-date objects:

```php
protected static function getSerializers(): array
{
    return [
        DateTimeInterface::class =>
            fn (DateTimeInterface $value): string => $value->format('c'),
    ];
}
```

In `toArray()`, resolve nested DataObjects first, then the `DateTimeInterface` serializer, then existing exact custom-object and `toArray()` paths. This covers custom date subclasses without listing every runtime class and does not change non-date extension behavior.

The date-specific branch is explicit while the existing exact-class extension point remains after it:

```php
if ($value instanceof self) {
    $value = $value->toArray();
} elseif (
    $value instanceof DateTimeInterface
    && $serializer = $serializers[DateTimeInterface::class] ?? null
) {
    $value = $serializer($value);
} elseif (
    is_object($value)
    && $serializer = $serializers[$value::class] ?? null
) {
    $value = $serializer($value);
} elseif (is_object($value) && method_exists($value, 'toArray')) {
    $value = $value->toArray();
}
```

### Matrix tests

Use one compact fixture with eight typed date properties and a data provider, not eight near-identical fixture classes. For each declared target, cover inputs of:

- database-format string;
- standard date string;
- UNIX timestamp including `0`;
- native mutable and native immutable DateTime;
- base mutable and base immutable Carbon;
- Hypervel mutable and Hypervel immutable Carbon.

Assert exact target class, instant, microseconds, and timezone. Then separately cover:

- interface targets under immutable default and mutable opt-out;
- concrete immutable targets while the factory is mutable, and concrete mutable targets while it is immutable;
- configured mutable and immutable Hypervel subclasses while every concrete Carbon target remains exact and retains Carbon-local settings;
- nullable/union resolution, nested data objects, missing/default values, auto-resolution disabled, and cache flush;
- explicit null for a non-nullable date target reaches the constructor as null and produces its natural parameter `TypeError`, rather than a Carbon error about an array;
- `toArray()` / JSON round trips for every target;
- user-overridden non-date dependency resolvers and serializers remain intact.

## 5. Fix immutable-sensitive behavior and native contracts

### Foundation lifecycle timestamps

Files:

- `src/foundation/src/Http/Kernel.php`
- `src/foundation/src/Console/Kernel.php`
- `tests/Foundation/Http/KernelTest.php`
- `tests/Foundation/Console/KernelTerminateTest.php`
- `tests/Integration/Console/CommandDurationThresholdTest.php`

Both timestamps are framework-owned internal values, so create and type them as exact `Hypervel\Support\CarbonImmutable`. Capture timezone conversion:

```php
$requestStartedAt = $requestStartedAt->setTimezone(
    $this->app->make('config')->string('app.timezone')
);
CoroutineContext::set(
    self::REQUEST_STARTED_AT_CONTEXT_KEY,
    $requestStartedAt
);
```

```php
$this->commandStartedAt = $this->commandStartedAt->setTimezone(
    $this->app->make('config')->string('app.timezone')
);
```

Return `?CarbonImmutable` from `requestStartedAt()` and `commandStartedAt()`. The HTTP value must be written back to `CoroutineContext` after timezone conversion because `requestStartedAt()` reads the context while lifecycle handlers are still running; the Console assignment already updates the owning property. Use immutable values for the end timestamp too. Test handler arguments, configured timezone, exact start value, threshold behavior, exception cleanup, and the null state after termination. From inside an HTTP duration handler, assert that `requestStartedAt()` returns the same converted instance and configured timezone as the handler argument. Retype the existing HTTP callback fixture from mutable Carbon.

### Scheduler minute boundary and mutex time

Files:

- `src/console/src/Commands/ScheduleRunCommand.php`
- `tests/Console/Scheduling/ScheduleRunCommandTest.php`

Preserve factory configurability of `$startedAt` as `CarbonInterface`, but never mutate it to compute the loop boundary:

```php
$endOfMinute = $this->startedAt->copy()->endOfMinute();

while (Date::now()->lte($endOfMinute)) {
    // pause/stop/repeatability checks...

    if (Date::now()->gt($endOfMinute)) {
        return;
    }

    // filters and execution...
}
```

Keep the copy even though the default is immutable because the public mutable opt-out is supported here. In `ScheduleRunCommandTest`, add a focused test that runs the repeat path in both default and mutable modes and proves the original minute—not end-of-minute—is passed from the command into the single-server mutex path. Retain Hypervel's existing `shouldStop`, pause, maintenance, Waiter, and dispatcher adaptations around the Laravel-shaped correction.

### Native DateTime return defects

Files:

- `src/validation/src/Concerns/ValidatesAttributes.php`
- `tests/Validation/ValidationValidatorTest.php`
- `src/mail/src/SendQueuedMailable.php`
- `tests/Mail/MailableQueuedTest.php`
- `src/notifications/src/SendQueuedNotifications.php`
- `tests/Notifications/NotificationSendQueuedNotificationTest.php`

Change the exhaustive concrete return set:

```php
protected function getDateTimeWithOptionalFormat(
    string $format,
    string $value
): ?DateTimeInterface;

protected function getDateTime(
    DateTimeInterface|string $value
): ?DateTimeInterface;

public function retryUntil(): ?DateTimeInterface;
```

There are four methods: two validation methods plus Mail and Notifications retry wrappers. No concrete-`DateTime` property case was found. Add default-immutable regressions for fallback parsing through `after`, `before`, and sibling `date_format`, plus queued mailable/notification objects whose property or method returns Hypervel immutable. Keep native `DateTime::createFromFormat()` values valid through the interface.

## 6. Migrate direct mutable construction deliberately

### Framework-owned direct immutable values

Replace mutable Hypervel or base Carbon imports/construction with `Hypervel\Support\CarbonImmutable` in the following audited files. Preserve existing Carbon APIs and captured modifier chains; do not translate them to integer arithmetic:

| Package | Files |
|---|---|
| Auth | `Notifications/VerifyEmail.php`, `Passwords/CacheTokenRepository.php`, `Passwords/DatabaseTokenRepository.php` |
| Cache | `Repository.php`, `SessionStore.php`, `StackStore.php`, `SwooleStore.php` |
| Collections | `LazyCollection.php` optional Carbon clock path |
| Console | `Commands/ScheduleListCommand.php`, direct log timestamps in `Commands/ScheduleRunCommand.php`, `Scheduling/ManagesFrequencies.php` |
| Database | `Concerns/BuildsWhereDateClauses.php`, `Eloquent/Factories/Factory.php` |
| Foundation | `Console/DownCommand.php`, `Console/Kernel.php`, `Http/Kernel.php`, `Http/MaintenanceModeBypassCookie.php` |
| HTTP | `Middleware/SetCacheHeaders.php` |
| Queue | `Console/PruneBatchesCommand.php`, `Console/PruneFailedJobsCommand.php`, `Console/WorkCommand.php`, `DatabaseQueue.php`, `Queue.php`, `Worker.php` |
| Routing | `UrlGenerator.php` |
| Session | `DatabaseSessionHandler.php`, `FileSessionHandler.php`, `Middleware/StartSession.php` |
| Telescope | `Console/PruneCommand.php`, `Http/Controllers/ExceptionController.php` |
| Testing | `TestResponse.php` |

Use exact immutable return types where a method owns the exact value, including:

```php
protected function now(): CarbonImmutable
{
    $queueTimezone = $this->config->get('queue.output_timezone');

    if (
        $queueTimezone
        && $queueTimezone !== $this->config->get('app.timezone')
    ) {
        return CarbonImmutable::now()->setTimezone($queueTimezone);
    }

    return CarbonImmutable::now();
}
```

and `ScheduleListCommand::getNextDueDateForEvent(): CarbonImmutable`. Remove now-redundant `copy()` calls only when the receiver is statically exact immutable and mutable opt-out cannot reach the path. Do not remove the scheduler boundary copy described above.

### Worker-array lock records and type-only imports

Canonicalize stored record types and creation together:

- `src/cache/src/AbstractArrayStore.php`
- `src/cache/src/ArrayLock.php`
- `src/cache/src/ArrayStore.php`
- `src/cache/src/WorkerArrayStore.php`

The final shapes use `?CarbonImmutable` for `expiresAt`. This is a framework-owned stored value and must remain immutable even if an application opts its public Date factory into mutable dates.

### Factory-routed construction sites

Keep public/configurable paths on `Date` and remove mutable intermediate creation:

- `src/database/src/Eloquent/Concerns/HasAttributes.php`
- `src/foundation/src/Http/Traits/HasCasts.php`
- `src/support/src/InteractsWithTime.php`
- `src/support/src/Sleep.php`
- `src/foundation/src/Testing/Concerns/InteractsWithTime.php`
- `src/foundation/src/Testing/Wormhole.php`
- `src/support/src/DataObject.php` (covered above)

For standard-date paths, create through the facade directly:

```php
return Date::parse($value)->startOfDay();
```

Do not generate mutable Carbon and immediately recast it through `Date::instance()`.

Use the same creator-owned formatted-parse path in Eloquent `HasAttributes::asDateTime()`, HTTP request `HasCasts::asDateTime()`, and DataObject. Keep the declared `CarbonInterface` return non-null without reverting to mutable Carbon:

```php
try {
    $date = Date::createFromFormat($format, $value);
    // @phpstan-ignore catch.neverThrown (the Date facade's magic dispatch hides Carbon's @throws from analysis)
} catch (InvalidFormatException) {
    $date = null;
}

return $date ?? Date::parse($value);
```

Carbon's `hasFormat()` rejects valid PHP format modifiers such as `!`, `|`, and `*`; `hasFormatWithModifiers()` supports those but Carbon 3.13.1 still rejects valid trailing-data `+` input that its own `createFromFormat()` accepts. Pre-validating with either method creates a second, incomplete grammar. The creator therefore owns the contract in all three paths. The dominant matching path performs one parse without a preflight regex; only genuine format mismatches pay the exception needed to reach generic parsing. Catch the exact `Carbon\Exceptions\InvalidFormatException`, let unrelated exceptions fail fast, and keep the identifier-scoped PHPStan suppression because facade magic cannot expose Carbon's runtime `@throws` metadata to analysis. Keep `Date::parse()` outside the `try` so a non-strict handler returning null cannot cause a failing fallback parse to be caught and invoked twice. The existing native `InvalidArgumentException` import in `HasAttributes` remains because the trait uses it elsewhere.

Retain Eloquent's existing `!` / escaped-literal / `|` / `|*` regressions and cover `+` plus a modifier/literal case in each separate owner. Do not add a shared parser helper or record Carbon's internal regex inconsistency as unfinished Hypervel work; no final Hypervel path depends on that approximation.

`HasAttributes::serializeDate()` may retain explicit Hypervel mutable conversion for mutable input and Hypervel immutable conversion for immutable input; this is a serialization boundary where preserving input mutability selection is intentional. Its existing `immutable_date` / `immutable_datetime` branches must now end at the Hypervel immutable subclass through the conversion override.

### Test-time APIs

Generate public test-time values through the Date factory while using Carbon's one shared static test clock:

```php
$now = Date::now();
Carbon::setTestNow($now->addDays($this->value));
```

Apply this pattern to freeze time/second, Wormhole units/back, Support `InteractsWithTime`, and Sleep's numeric-until/sync behavior. Update callback PHPDocs from concrete mutable Carbon to `CarbonInterface` where the framework supplies the value. Test exact immutable default and exact mutable opt-out, callback return values, and `finally` restoration after exceptions.

Retain `WithImmutableDates`, switch it to `Hypervel\Support\CarbonImmutable`, and document that it forces immutability for a test whose application/bootstrap may globally opt into mutable dates. Do not add `WithMutableDates` without a demonstrated consumer.

## 7. Canonicalize existing base CarbonImmutable values

All listed packages already depend on `hypervel/support`; changing the class does not add split-package dependencies. Replace base `Carbon\CarbonImmutable` with `Hypervel\Support\CarbonImmutable` in this exhaustive source set:

| Package | Files |
|---|---|
| Bus | `Batch.php`, `BatchFactory.php`, `Batchable.php`, `DatabaseBatchRepository.php`, `DebounceLock.php` |
| Database | the immutable import/conversion in `Eloquent/Concerns/HasAttributes.php` |
| Foundation | `WorkerCachedMaintenanceMode.php` |
| gRPC | `Server/Middleware/HandleCall.php`, `Server/ServerCallContext.php` |
| Horizon | `Jobs/RetryFailedJob.php`; `Listeners/MonitorWaitTimes.php`, `TrimFailedJobs.php`, `TrimMonitoredJobs.php`, `TrimRecentJobs.php`; `MasterSupervisor.php`; `ProcessPool.php`; `Repositories/RedisJobRepository.php`, `RedisMasterSupervisorRepository.php`, `RedisMetricsRepository.php`, `RedisProcessRepository.php`, `RedisSupervisorRepository.php`; `Supervisor.php`; `WorkerProcess.php` |
| Support fakes | `Testing/Fakes/BatchFake.php`, `Testing/Fakes/BatchRepositoryFake.php` |
| Testbench | `Attributes/WithImmutableDates.php`, `src/testbench/workbench/database/migrations/2013_07_26_182750_create_testbench_users_table.php` |

Update their exact source tests to assert the Hypervel class, especially property types and values crossing repository hydration. Base Carbon remains appropriate only in:

- the two Hypervel classes' inheritance imports;
- DataObject's explicitly supported base-class declared targets;
- tests that intentionally exercise third-party/base Carbon input or conversion;
- installed Carbon `Factory` / `FactoryImmutable` infrastructure.

Do not create aliases or accept two framework-owned immutable property types merely to reduce edits.

## 8. Correct public/model metadata and affected tests

### Model and framework PHPDocs

- change `HasAttributes::asDate()` / `asDateTime()` docs to `CarbonInterface` and remove mutable-only prose;
- change `src/passkeys/src/Passkey.php` `last_used_at`, `created_at`, and `updated_at` properties to `?CarbonInterface`;
- change `src/sanctum/src/PersonalAccessToken.php` `last_used_at` and `expires_at` properties from base mutable Carbon to `?CarbonInterface`;
- replace `src/telescope/src/EntryResult.php`'s stale mutable/base Carbon parameter union with its existing native `CarbonInterface` contract;
- change cache record shapes to exact Hypervel immutable as above;
- change Notification documentation callback/delay returns to `CarbonInterface`;
- update all DateFactory/Date facade/helper metadata from concrete mutable output to `CarbonInterface`.

### Existing test migration

Update expectations by semantics, never by weakening every assertion to the interface:

- default factory/cast output: assert exact `Hypervel\Support\CarbonImmutable`;
- explicit mutable opt-out/conversion: assert exact `Hypervel\Support\Carbon`;
- configurable contract-only boundaries: assert `CarbonInterface` plus the expected exact class for each configuration;
- base-class input/output boundary tests: retain base assertions intentionally.

The known assertion files are:

- `tests/Database/DatabaseEloquentModelTest.php`
- `tests/Database/DatabaseEloquentSoftDeletesIntegrationTest.php`
- `tests/Database/DatabaseSoftDeletingTest.php`
- `tests/Database/DatabaseSoftDeletingTraitTest.php`
- `tests/Database/Eloquent/Concerns/DateFactoryTest.php`
- `tests/Foundation/FoundationHelpersTest.php`
- `tests/Integration/Database/EloquentModelDateCastingTest.php`
- `tests/Support/DateFacadeTest.php`
- `tests/Support/Traits/InteractsWithDataTest.php`
- `tests/Testbench/DefaultConfigurationTest.php` — rename the former mutable-default test and assert the exact immutable default; this interface-only test has no concrete mutable Carbon import and therefore requires the full type sweep below

`tests/Support/SupportCarbonTest.php` continues to test the explicit mutable class and is not blanket-converted. Strengthen immutable-cast tests that currently pass merely because the Hypervel subclass is an `instanceof` the base class.

Correct non-assertion native test types found by the audit:

- the two static `?Carbon $ranAt` properties in `tests/Integration/Queue/JobChainingTest.php` become `?CarbonInterface` or exact immutable according to the value source (`now()` is configurable, so use the interface);
- the Foundation HTTP duration callback parameter becomes exact `CarbonImmutable`, matching the public kernel contract;
- `tests/Console/Scheduling/CacheSchedulingMutexTest.php::$time` becomes exact `CarbonImmutable` because that fixture is not testing the opt-out;
- `tests/Console/Scheduling/ScheduleRunCommandTest.php::invokeRunEvents()` accepts `?CarbonInterface` and defaults through `Date::now()` because that test owner covers mutable opt-out;
- base immutable imports in Bus, Horizon, gRPC, Foundation clock, WorkerCachedMaintenanceMode, Queue, and database tests switch to Hypervel immutable unless the test is intentionally a base-input boundary.

Run a full test-tree concrete-type sweep after edits; the known list is a starting ledger, not permission to ignore new matches.

The current base-immutable test imports are a second explicit ledger: `tests/Bus/BusBatchTest.php`, `BusBatchableTest.php`; `tests/Database/Eloquent/Concerns/DateFactoryTest.php`; `tests/Foundation/Providers/FoundationServiceProviderTest.php`, `Testing/WormholeTest.php`, `WorkerCachedMaintenanceModeTest.php`; `tests/Grpc/ServerCallContextTest.php`; `tests/Integration/Database/EloquentModelDateCastingTest.php`, `EloquentModelImmutableDateCastingTest.php`; the Horizon feature tests `JobRetrievalTest.php`, `MetricsTest.php`, `MonitorWaitTimesTest.php`, `ProcessRepositoryTest.php`, `QueueProcessingTest.php`, `SupervisorTest.php`, `TrimMonitoredJobsTest.php`, `TrimRecentJobsTest.php`, `WorkerProcessTest.php`; `tests/Integration/Queue/DebouncedJobTest.php`, `SkipIfBatchCancelledTest.php`; `tests/Jwt/ClaimFactoryTest.php`; `tests/Permission/Traits/HasPermissionsWithCustomModelsTest.php`, `HasRolesWithCustomModelsTest.php`; `tests/Queue/RetryBatchCommandTest.php`; and `tests/Support/DateFacadeTest.php`, `SupportCarbonTest.php` (aliased as `BaseCarbonImmutable`). Switch framework-owned expected values to Hypervel immutable; retain a base immutable only where the test explicitly supplies a third-party/base input and assert the canonical output separately. `SupportCarbonTest` intentionally retains the aliased base class where it verifies shared mutable/immutable test-clock state.

### Canonical test-setup sweep

The audit also found mutable Hypervel Carbon imported as test setup in the following current files. Read each before editing. Move ordinary clocks, timestamps, expected framework values, and immutable-source fixtures to `CarbonImmutable` or `Date` according to the production boundary; retain mutable Carbon only where the test explicitly proves mutable opt-out, mutable conversion, mutable serialization, or a custom mutable cast:

| Area | Files |
|---|---|
| Auth | `tests/Auth/AuthDatabaseTokenRepositoryTest.php`, `CacheTokenRepositoryTest.php`, `RequirePasswordMiddlewareTest.php` |
| Cache | `tests/Cache/CacheArrayStoreTest.php`, `CacheFileStoreTest.php`, `CacheMemoizedStoreTest.php`, `CacheRepositoryTest.php`, `CacheSessionStoreTest.php`, `CacheWorkerArrayStoreTest.php` |
| Console scheduling | `tests/Console/Scheduling/CacheSchedulingMutexTest.php`, `EventTest.php`, `FrequencyTest.php`, `ScheduleRunCommandTest.php`, `ScheduleRunContextPropagationTest.php`, `ScheduleTest.php` |
| Database | `tests/Database/DatabaseEloquentBelongsToManyCreateOrFirstTest.php`, `DatabaseEloquentBelongsToManySyncTouchesParentTest.php`, `DatabaseEloquentBuilderCreateOrFirstTest.php`, `DatabaseEloquentBuilderTest.php`, `DatabaseEloquentHasManyCreateOrFirstTest.php`, `DatabaseEloquentHasManyThroughCreateOrFirstTest.php`, `DatabaseEloquentIntegrationTest.php`, `DatabaseEloquentIrregularPluralTest.php`, `DatabaseEloquentModelTest.php`, `DatabaseEloquentRelationTest.php`, `DatabaseEloquentSoftDeletesIntegrationTest.php`, `DatabaseEloquentTimestampsTest.php`, `DatabaseSoftDeletingTest.php`, `DatabaseSoftDeletingTraitTest.php`, `QueryDurationThresholdTest.php` |
| Foundation | `tests/Foundation/Console/KernelTerminateTest.php`, `FoundationExceptionsHandlerTest.php`, `FoundationInteractsWithTimeTest.php`, `Http/MaintenanceModeBypassCookieTest.php`, `Providers/FoundationServiceProviderTest.php`, `Testing/WormholeTest.php` |
| Other unit packages | `tests/Http/HttpClientTest.php`, `tests/Pagination/CursorTest.php`, `tests/Sanctum/PruneExpiredTest.php`, `tests/Session/ArraySessionHandlerTest.php`, `tests/Session/FileSessionHandlerTest.php`, `tests/Telescope/Watchers/QueryWatcherTest.php`, `tests/Translation/TranslationTranslatorTest.php`, `tests/Validation/ValidationDateRuleTest.php`, `tests/Validation/ValidationValidatorTest.php` |
| Integration: Cache/Console/Foundation | `tests/Integration/Cache/RepositoryTest.php`, `tests/Integration/Console/CommandDurationThresholdTest.php`, `CommandSchedulingTest.php`, `Scheduling/ScheduleGroupTest.php`, `Scheduling/ScheduleListCommandTest.php`, `Scheduling/ScheduleTestCommandTest.php`, `Scheduling/SubMinuteSchedulingTest.php`, `tests/Integration/Foundation/Configuration/WithScheduleTest.php`, `MaintenanceModeTest.php` |
| Integration: Database | `tests/Integration/Database/DatabaseCacheStoreTest.php`, `DatabaseEloquentModelAttributeCastingTest.php`, `DatabaseEloquentModelCustomCastingTest.php`, `DatabaseLockTest.php`, `EloquentBelongsToManyTest.php`, `EloquentEagerLoadingLimitTest.php`, `EloquentModelTest.php`, `EloquentMorphManyTest.php`, `MariaDb/EloquentCastTest.php`, `MySql/EloquentCastTest.php`, `QueryBuilderTest.php` |
| Integration: other | `tests/Integration/Http/ThrottleRequestsWithRedisTest.php`, `tests/Integration/Mail/SendingMailWithLocaleTest.php`, `tests/Integration/Notifications/SendingNotificationsWithLocaleTest.php`, `tests/Integration/Queue/JobChainingTest.php`, `RateLimitedTest.php`, `ThrottlesExceptionsTest.php`, `ThrottlesExceptionsWithRedisTest.php`, `WorkCommandTest.php`, `tests/Integration/Routing/UrlSigningTest.php`, `tests/Integration/Session/DatabaseSessionHandlerTest.php` |
| Queue | `tests/Queue/DatabaseFailedJobProviderTest.php`, `DatabaseUuidFailedJobProviderTest.php`, `FileFailedJobProviderTest.php`, `QueueBackgroundQueueTest.php`, `QueueBeanstalkdQueueTest.php`, `QueueDatabaseQueueIntegrationTest.php`, `QueueDatabaseQueueUnitTest.php`, `QueueDeferredQueueTest.php`, `QueuePauseResumeTest.php`, `QueueRedisQueueTest.php`, `QueueSqsQueueTest.php`, `QueueWorkerTest.php` |
| Support | `tests/Support/DateFacadeTest.php`, `SleepTest.php`, `SupportArrTest.php`, `SupportCarbonTest.php`, `SupportFluentTest.php`, `SupportLazyCollectionIsLazyTest.php`, `SupportLazyCollectionTest.php`, `SupportStringableTest.php`, `Traits/InteractsWithDataTest.php`, `ValidatedInputTest.php` |

`SupportCarbonTest`, explicit mutable DateFactory branches, and the custom mutable Eloquent caster fixtures are intentional allowlist candidates; ordinary test clocks are not. Correct stale test PHPDocs encountered in this sweep, including `QueryDurationThresholdTest::$now`, and capture modifier returns where switching a fixture to immutable exposes an assumed side effect.

## 9. Documentation, policy, and release guidance

### Contributor and AI policy

Files:

- `AGENTS.md`
- `docs/ai/differences-vs-laravel.md`
- `docs/todo.md`

Add the approved modernization to Porting Packages: mutable Laravel date construction is converted to Hypervel immutable construction, concrete factory outputs are typed `CarbonInterface`, and discarded mutation returns are captured. Add a Code conventions rule with these points:

- Hypervel defaults to `Hypervel\Support\CarbonImmutable` where Laravel defaults to mutable Carbon;
- use `Date` / helpers for public or application-configurable creation;
- use exact Hypervel immutable for framework-owned internal/held values;
- use `CarbonInterface` at configurable Carbon boundaries and `DateTimeInterface` at native/third-party boundaries;
- capture the return of every modifier that must persist;
- use mutable Hypervel Carbon only for explicit opt-out/conversion behavior.

Add a concise `Dates` difference explaining immutable default, modifier reassignment, and the boot-time mutable opt-out. Remove the completed CarbonImmutable todo entry; do not leave a duplicate historical instruction.

### Active user documentation

Audit and update these known active surfaces:

- `src/boost/docs/helpers.md` — exact immutable default, helpers, `plus` / `minus`, direct class example, and mutable opt-out;
- `src/boost/docs/eloquent-mutators.md` and `eloquent.md` — `date` / `datetime` default to Hypervel immutable, explicit immutable casts, interface language, and assignment examples;
- `src/boost/docs/data-objects.md` — all eight target types and interface-vs-concrete behavior;
- `src/boost/docs/mocking.md` — immutable default and `CarbonInterface` callback examples;
- `src/boost/docs/testbench.md` — `WithImmutableDates` is a forcing attribute for suites/apps that otherwise opt into mutable;
- `src/boost/docs/collections.md` — immutable class in date examples;
- `src/boost/docs/notifications.md` — `CarbonInterface` return types;
- `src/boost/docs/requests.md` — request dates use the configured Carbon interface and immutable default;
- `src/boost/docs/strings.md` — `CarbonImmutable::createFromId()` as the default example while noting the mutable class retains parity;
- `src/boost/docs/grpc.md` — name the Hypervel immutable class, not an ambiguous/base class;
- any remaining active `Hypervel\Support\Carbon` default-output claim found by the final docs sweep.

Examples must not teach ignored mutation returns. Use either chaining into immediate consumption or assignment:

```php
$expiresAt = now()->addMinutes(5);
$expiresAt = $expiresAt->addDay();
```

### Upgrade and release notes

Update `src/boost/docs/upgrade.md` with a migration section:

- concrete mutable type hints receiving helper/factory/cast values become `CarbonInterface` or immutable;
- retained modifier calls require assignment;
- applications that temporarily require the old behavior may configure `Date::use(Carbon::class)` during boot;
- custom Date class handlers must implement `CarbonInterface`; callable handlers may deliberately transform the generated date but must still return a `CarbonInterface`, because typed helpers and other factory-routed APIs enforce that contract.

Add a concise immutable-date item to the 0.4 release notes in `src/boost/docs/releases.md`. Do not frame this as Swoole-only: immutable date values are the modern default, while worker/coroutine safety is an additional benefit for held/shared values.

## 10. Verification and completion criteria

### Focused implementation loop

After each workstream, run its tests immediately. At minimum:

```bash
vendor/bin/phpunit tests/Support/SupportCarbonTest.php tests/Support/SupportCarbonImmutableTest.php tests/Support/DateFacadeTest.php tests/Support/DataObjectTest.php tests/Support/SupportStringableTest.php
vendor/bin/phpunit tests/Foundation/Providers/FoundationServiceProviderTest.php tests/Testing/PHPUnit/AfterEachTestSubscriberTest.php
vendor/bin/phpunit tests/Foundation/Http/KernelTest.php tests/Foundation/Console/KernelTerminateTest.php tests/Integration/Console/CommandDurationThresholdTest.php
vendor/bin/phpunit tests/Console/Scheduling/ScheduleRunCommandTest.php tests/Console/Scheduling/CacheSchedulingMutexTest.php
vendor/bin/phpunit tests/Validation/ValidationValidatorTest.php
```

Run the affected Mail, Notifications, Database/Eloquent, Bus, Cache, gRPC, Horizon, Queue, Session, Testbench, and Testing test files/directories as their owners are edited. Use the copied worktree `.env` for integration services.

### Static and generated metadata

```bash
php -f src/facade-documenter/facade.php -- --lint Hypervel\\Support\\Facades\\Date
composer analyse
composer lint:fix
```

Fix source types and PHPDocs at the owner. Do not add global PHPStan suppressions, runtime `assert()` narrowing, or defensive branches that only compensate for stale metadata.

### Full repository validation

```bash
composer test:parallel
composer test:testbench
composer test:dogfood
composer fix
php -f src/facade-documenter/facade.php -- --lint Hypervel\\Support\\Facades\\Date
```

`composer fix` is the final combined formatter, analysis, parallel-suite, Testbench, and dogfood confirmation. Run the Date facade lint again afterward because the formatter and generator must converge on the same committed metadata representation. Record any environment-dependent failure with the exact command and evidence; do not silently omit a suite.

### Structural/stale-code sweep

Repeat broad searches over all of `src/`, `tests/`, active docs, stubs, dogfood, and types. Completion requires:

- every root and split-package Carbon constraint requires `^3.13.1`;
- no default-output metadata claiming `Hypervel\Support\Carbon`;
- no factory-produced value assigned to a concrete mutable-only property, parameter, or return;
- no concrete `?DateTime` return in the four corrected methods;
- no direct mutable Carbon construction except the explicit mutable class itself, mutable opt-out tests, DataObject's declared mutable target, and mutable-input serialization;
- no base `Carbon\CarbonImmutable` framework property/construction outside the documented inheritance/input boundary allowlist;
- no date modifier return discarded where persistence is intended;
- no mutable `expiresAt` cache record shape;
- no `DateFactory::use(DateTime::class)` success test or non-Carbon `CustomDateClass` fixture;
- no redundant immutable `setTestNow()` reset or dual-set Hypervel override;
- no stale imports, `copy()` calls on exact immutable-only values, comments describing mutable-only side effects, or docs teaching mutable defaults;
- no CarbonImmutable todo entry after the work is complete.

Use the following searches as a reproducible minimum, then inspect the surrounding code rather than treating zero raw matches as the audit:

```bash
rg -n '^use Hypervel\\Support\\Carbon;' src tests
rg -n '^use Carbon\\Carbon(Immutable)?;' src tests
rg -n 'Carbon::(now|today|parse|create|instance)|new Carbon' src tests
rg -n 'CarbonImmutable|CarbonInterface|DateTimeInterface|\?DateTime|\?Carbon' src tests
rg -n 'Date::use|DateFactory::use|useClass\(|useFactory\(|useCallable\(' src tests
rg -n -- '->(add|sub|startOf|endOf|setTimezone|timezone|modify|setDate|setTime|setTimestamp)' src tests
rg -n -F -e 'Hypervel\Support\Carbon' -e 'CarbonImmutable' -e 'CarbonInterface' -e 'Date::use' src/boost/docs docs AGENTS.md
```

The full type search is required in addition to concrete Carbon-import ledgers: it covers stale default assertions that mention only interfaces or native date types, such as Testbench's default-configuration contract.

For mutation results, re-read every match and its value flow; a chained immediate comparison/format is correct, while a standalone modifier intended to persist is not. Review PHPStan's parsed full-source result as the AST/type safety net. Do not commit a custom scanner or lint rule for this one migration.

Review every changed file in the final diff, not just matching lines, and compare every Laravel-derived structural edit against the current local 13.x reference plus the approved Hypervel adaptations. Run `git status --short` and inspect generated/fixture deletions so the final worktree contains only intentional source, tests, documentation, and plan changes.

### Explicit non-changes

- no new clock abstraction or service beyond the existing PSR binding;
- no Symfony global clock installation;
- no replacement of Carbon expiry/deadline code with raw integers or `hrtime()`;
- no benchmark gate or permanent performance harness;
- no macro synchronization layer or per-date-class cleanup registry;
- no runtime guard on every callable-produced date;
- no generic DataObject converter registry;
- no `WithMutableDates` attribute without a real consumer;
- no compatibility wrapper for `DateTime::class` or arbitrary non-Carbon DateFactory class handlers;
- no changes to intentional existing monotonic timers and native timestamp protocols;
- no private-package edits in this framework worktree.

The finished codebase has one canonical immutable Hypervel value class, one explicit mutable counterpart, one configurable Date factory, one PSR clock binding, and one Carbon static-state cleanup path.
