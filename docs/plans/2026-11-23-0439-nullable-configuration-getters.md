# Nullable configuration getters and typed reads

Give framework consumers a checked read for a setting that accepts one type or null. Migrate all applicable reads in components and enable an opt-in PHPStan rule in this repository so new generic reads have a reason. Keep Laravel's keyed helper, bulk reads, typed getters and their extension points available to applications.

## Repository API

In `src/contracts/src/Config/Repository.php` and `src/config/src/Repository.php`, add `nullableString(): ?string`, `nullableInteger(): ?int`, `nullableFloat(): ?float`, `nullableBoolean(): ?bool` and `nullableArray(): ?array`. Each takes `string $key, mixed $default = null`. Use the existing getters' Laravel-style titles, typed default PHPDoc (including a lazy Closure), native return types and array generics. Document that omitting the default requires the key. Also declare the existing `collection()` on the contract with its concrete signature and generics. This is a public interface expansion: direct implementations must provide six additional methods. Extending the standard repository supplies them.

| Repository contents | Call | Result |
| --- | --- | --- |
| Key contains a value of the requested type | With or without a default | Return that value, including false, zero, an empty string or an empty array. |
| Key contains null | With or without a default | Return null. Leave any default closure unevaluated. |
| Key is absent | Default omitted | Throw `InvalidArgumentException` naming the missing key. |
| Key is absent | Default supplied, including explicit null | Use the default, evaluating a Closure once. Check the result just like a configured value. |
| Configured value or evaluated default has the wrong type | Any | Throw `InvalidArgumentException` naming the key, accepted type and actual type. No scalar coercion. |

```php
$wrap = $config->nullableString('data.wrap');
$channel = $config->nullableArray("logging.channels.{$name}", null);
```

Use one lookup through the overridable `get()`. Each new getter chooses its path by argument count and then checks its own type, for example:

```php
$value = func_num_args() === 1 ? $this->getRequired($key) : $this->get($key, $default);

if ($value !== null && ! is_string($value)) {
    throw new InvalidArgumentException(
        sprintf('Configuration value for key [%s] must be a string or null, %s given.', $key, gettype($value))
    );
}

return $value;
```

Implement the shared private `getRequired(string $key): mixed` using `static $missing = new stdClass;` and `$value = $this->get($key, $missing)`. On identity with the marker, throw `Configuration value for key [%s] is not defined.` Otherwise return the value. The marker has bounded lifetime storage, carries no request data, and only its identity matters. This preserves dot-key lookup and distinguishes absence from stored null without a second traversal or an allocation per read. Existing non-nullable getters still pass the caller's default (including their implicit null) to `get()`; `collection()` still calls `array($key, $default)`. Subclasses can observe both. Keep the existing implementations and exception behavior. Keep one key per typed call. The existing generic APIs cover bulk reads and values with no matching getter.

Regenerate and check the Config facade annotations from the repository root:

```sh
composer facade -- 'Hypervel\Support\Facades\Config'
composer facade -- --lint 'Hypervel\Support\Facades\Config'
```

## PHPStan value types and diagnostics

1. Extend `src/phpstan/src/Internal/ConfigHelper.php`, used by the existing repository, facade and helper return-type extensions. Recognize the five method names case-insensitively and intersect the looked-up value with the accepted type, including null. Preserve literal types and array shapes. Only the application's known config repository supplies application-specific types. Keep declared return types for injected or separately constructed repositories and the existing protections for overridden methods. Calls on unknown keys and unpacked arguments keep the applicable declared result.
2. Preserve the presence information from `ConfigParser::getValue()` until the consumer of the lookup has applied its default semantics. A successful required nullable lookup includes the type of each possibly present value and excludes the missing path. A definitely absent required key gives PHPStan's existing error type. With an explicit default use the current absent-key and lazy-Closure logic. An explicitly configured null never activates a default. Ordinary `get()` and keyed `config()` still contribute null for absence without a default. Reuse a shared calculation for these rules and dynamic return types, maintaining result-cache dependency tracking. Keep unsupported or unknown lookups distinct from known absence.
3. Extend `ConfigTypeRule` to check the five nullable getters. For a definitely missing key with an omitted default, give a missing-key diagnostic for all typed getters, including `collection()` in `ConfigCollectionRule`. Tell the reader to check the key or its configuration. For a present but incompatible type, retain the type diagnostic. For union keys report an always-failing call only when all possible branches fail, and describe absence as certain only when it really is. Preserve the existing diagnostic identifiers and feature switches. Never advise adding a null default to hide a missing required key.
4. Extend the app-only recommendation in `TypedConfigGetterRule`. For definitely present keys, remove null from a proven union and recommend the corresponding nullable getter only when the remainder is a single supported category. Keep the existing non-nullable recommendation for a non-null value. Null alone, unresolved env values, and unions spanning several non-null categories do not identify a getter. Check known alternatives together. Keep valid explicit defaults; avoid a specific replacement that would reject the supplied default or its Closure result. Don't derive a package's accepted types from its shipped defaults.
5. Update the other interpreters of config reads. Add the five lowercase method names to `ConfigSourceResolver::READ_METHODS`: a provider that only reads a value, including by creating a callable, must not invalidate unrelated configuration inference as an unknown writer. In `SchemaAggregator::evaluateConnectionName()` recognize `nullableString()` on the already supported receivers. A present null or missing key with an explicit null can select the default connection; a missing required key or incompatible value makes the expression unevaluable. Keep the existing limits on evaluating expressions and defaults. Use the existing tracked config reads in `ConnectionConfigResolver`, and bump `MigrationCache::CACHE_FORMAT_VERSION` because aggregation results change.

## Opt-in authoring rule

Add boolean `noUntypedConfigReads` to the defaults and schema in `src/phpstan/extension.neon`. Default it to false. Enable it in the root production `phpstan.neon.dist`; the distributed extension, starter skeleton and isolated fixture configurations retain their defaults. It applies equally when the analyzer boots an application or a package.

Implement `NoUntypedConfigReadsRule` using the existing conditional registration pattern. Report these **calls** even with a default, a variable key or an array of keys:

- Global `config($key, ...)` when the key is definitely a string. Resolve the function's identity and the named or positional key. Zero arguments, null, and array writes are distinct modes. Skip argument unpacking or ambiguous key types when the helper's operation cannot be established.
- `get()` and `getMany()` on the Config facade and known config repository receivers, including injected properties, parameters, local instances, `config()`, container resolutions and nullsafe calls. A named repository method still identifies a read when its arguments are unpacked. For nullsafe calls check the non-null receiver type and skip a definitely null receiver. Match config repositories by their contract type; a merely possible repository among unrelated types is insufficient. Method spelling alone never identifies a config repository.
- Skip first-class callable creation. The rule covers named calls; repository array-offset syntax is outside its scope. Exempt `$this` reads only when lexically inside the framework's concrete `Hypervel\Config\Repository`. Subclasses and their own business logic are checked. Give the keyed helper's internal `get()` forwarding call a local reason.

Keep policy and type inference separate. The strict rule identifies **which API was called** even if the value is unknown. Share the existing recommendation calculation with the standard rule, preserving its application-repository provenance check. With strict mode active the standard rule defers, so the sole identifier for an untyped read is `hypervel.untypedConfigRead`. Strict mode works with `checkTypedConfigGetters` either on or off. With strict mode off the standard rule retains its own switch and identifier. Use the conditional-rule and parameter-injection precedent in `NoPublicModelScopeAndAccessorRule`.

Name the getter in the message when established. Otherwise ask the reader to choose the getter that covers the caller's supported values. The tip should explain that a generic read is appropriate for a union no getter covers, arbitrary values, or raw input needed to diagnose invalid configuration. Show how to attach a local reason. Offer no automatic rewrite. For example:

```php
// @phpstan-ignore hypervel.untypedConfigRead (Compression accepts an enum instance, a string, or null.)
$compression = $this->compression($config->get('grpc.server.compression'));
```

## Convert the framework reads

Audit framework consumers throughout the tracked components tree, including code outside `phpstan.neon.dist`. Use strict analysis for known repository receivers. Also search for keyed helpers, aliases, facade calls, repository methods, container resolutions, wrappers and multi-key reads. Trace wrapper callers. Include package source, service providers, config files, routes, migrations, published templates and stubs, Blade, skeletons, workbench, testbench and executable tooling. In tests, migrate Hypervel-owned support code that consumes a setting. Preserve direct assertion reads, generic API and analyzer fixtures, and upstream-ported test bodies except for expectations affected by the production change. Add focused regression coverage as described below. Use source and tests to distinguish config reads from helper writes and unrelated APIs with the same names.

Process related consumers together. For an ordinary single-type setting use its existing typed getter. For one type plus null choose the corresponding new method. Supply the documented fallback, including explicit null, when absence is valid. Omit the default for a required key. Treat named records and user-replaceable nested arrays according to their omission rules. A variable key does not imply a variable result type. Verify normalized config and supported overrides before choosing a scalar getter, especially integer versus float and uncast env values. Preserve the surrounding access style: use the injected repository when one exists and the corresponding facade or helper where the package already uses it.

| Consumers | What to preserve or simplify |
| --- | --- |
| Sanctum token expiration and cache store, Passport issuer, store and connection, JWT TTLs | Collapse Sanctum's double read and primitive checks. Cover Passport models **and migrations** using the same connection key. Keep model connection overrides, empty-string normalization, store validation, guard-record precedence, and domain checks such as positive TTLs. |
| Logging channels, Pennant stores, Swoole tables, Socialite provider records | A named entry may be absent. For Socialite, passing null lets the provider report the missing credentials; validate the record without intercepting this domain error. |
| Saloon stores and Telescope queue settings | Read configured store, connection and queue names as strings or null. Callers can still pass enum cases to manager and queue APIs that accept them. Read Telescope's normalized delay as an optional nullable integer. |
| Data config, Scout job options, Inertia SSR | Replace DataConfig's private nullable-string reader and use the repository getter beneath its positive-integer validation. Preserve required-key checks, class-string checks, and the accepted string/list contract of `data.date_format`. Scout backoff accepts several types. Inertia's configured null omits the HTTP option, while a missing key uses the documented numeric default. Preserve the accepted numeric types at each boundary. |
| Inspection, diagnostics and generic configuration | Keep reasoned generic reads for `config:show`, gRPC compression, Telescope watcher definitions and Testbench copying differently typed connection fields. Preserve exception renderer and deprecation-handler behavior during early boot or failure. Published files must work with the strict rule off as well as on. For the Permission migrations, use `nullableArray('permission.table_names', null)` so absent, null and empty settings retain each migration's recovery message and malformed scalars get the key-specific type error. Keep strict-rule ignores out of code copied into applications. |
| PHPStan inspecting an application | `LoadsAuthModel` returns no models when guard or provider records are malformed. `RuntimeInputs` skips its SQLite file check for non-string values. `ConnectionConfigResolver::config()` records which keys the analyzer reads and supplies raw values of several types. Keep these reads tolerant, with a local reason at each reported read, including once inside the resolver wrapper. |

Review the remaining packages with the same standard, including AI provider selections, queue, Fortify/passkeys, Horizon, auth, mail, permission and database integrations. Check each migration and its published form. In the ordinary welcome and workbench layout views, read the shipped application name with `config()->string('app.name')`, as the mail layout already does. The exception-renderer and health-page titles retain their fallback when the key is missing or null. Retain documentation examples that specifically teach the supported generic APIs.

Remove obsolete assertions, PHPDoc casts, helpers, imports, comments and duplicate lookups. Keep range, shape and class-contract checks. Where a getter replaces a primitive check, a wrong-type setting raises its key-specific `InvalidArgumentException`. Update affected exact-message or exception-class expectations for that same invalid input. Retain domain exceptions for values the getter admits, and checks on non-config inputs. For example, Passkeys' configured relying-party ID and user-handle secret accept null at lookup and still raise their existing `RuntimeException` for a null value or empty string when used. The request-aware relying-party callback keeps its own validation and host-specific error. Preserve inspection paths whose purpose is to diagnose malformed values.

## Optional settings and integration checks

Preserve omission of AI's conversation connection, Permission's optional model names, and the application URL used for Mercure and the fallback request. Passport models and migrations read the shipped connection key; register the provider in their integration tests. Keep `app.asset_url` required and read it once when hashing the Inertia version. Isolate deliberately killed remote queue workers in their own Testbench runtime. The broadcasting installer writes to the selected application environment file. Mirror the split analyzer's Composer installer in root development dependencies with its plugin disabled for the explicit root rulesets.

## Documentation

- Update the typed-values section of `src/docs/configuration.md` with the five getters, required versus optional keys, lazy defaults and a stored-null example. A generic getter remains appropriate for values with no single matching getter.
- Update the configuration inference and typed-getter sections of `src/docs/static-analysis.md`, the parameter and identifier tables, and the strict rule's examples and limits. Distinguish enforcing the call form in packages from proving a getter in applications.
- Add the Config contract to `Differences From Laravel` in `src/contracts/README.md`. List its five existing primitive getters, `collection()` and the five nullable getters. Under Configuration in `src/docs/porting-from-laravel.md`, direct custom repository implementers to the full Hypervel contract or the concrete base. Keep the full getter documentation in one place.
- Update only the Configuration and Static Analysis guidance in `AGENTS.md` to match the new methods, selection by supported type and local-ignore convention. Keep the rule requiring tests for supported null behavior.

## Testing and completion

Run checks sequentially from the repository root with a timeout appropriate to each job. Run each changed test class as soon as it is ready. For a fixture, run its owning test immediately. Use `./vendor/bin/phpunit --no-progress <test-file>`, adding `-c phpunit.phpstan.xml.dist` for analyzer tests.

1. Extend `tests/Config/RepositoryTest.php` with shared cases for all five types. Cover present null with a non-null default, exact types and falsy values, wrong input, missing required, explicit null, literal and lazy defaults, invalid default results and a closure that must not run. Exercise dotted keys, named arguments through the facade, and an overridden `get()` to verify single dispatch and preserve existing getter behavior.
2. Extend the fixtures behind `ConfigDynamicReturnTypeExtensionTest`, `ConfigTypeRuleTest`, `ConfigCollectionRuleTest` and `TypedConfigGetterRuleTest`. Cover known, absent, maybe-present and unknown keys, present null, exact shapes, closure defaults, wrong and mixed unions, injected repositories, overridden methods, facade and named calls. Include an analyzable `T|null` and an uncast env union. Verify that absent-required advice names the actual problem. In `ConfigSourceResolverTest` exercise a provider using the new read methods; in `SchemaAggregatorTest` exercise nullable-string connection routing, including a configured name, stored null, missing required key and explicitly defaulted keys.
3. Add strict rule tests for the call forms and limits above. Exercise package and app analysis, inspect both the identifier and the tip, and run a fixture through both registered rules to prove exactly one diagnostic and a working local ignore. Verify the four switch combinations in a configured application. Include multi-key reads, reordered arguments, injected and unrelated receivers, helper writes, callable creation, the core exemption and a subclass that still gets checked.
4. Run focused consumer tests for each migrated group, extending existing coverage where the new behavior matters. Cover the required-versus-optional distinction, retained domain errors, the DataConfig union and range checks, Passport's connection precedence and migration analysis, and nullable cache/store/timeout behavior. When touching Testbench run `composer test:testbench`. For multiple related runtime test files use `composer test:parallel -- --processes=4` with a file or filter selection.
5. At coherent verification checkpoints run `composer analyse`. Before code review run `composer lint:fix` and inspect its diff. Install dependencies in `skeletons/hypervel` for the nested analysis and formatting scripts. The ordinary Blade title uses an existing getter and needs no new split-package installation setup. Recheck the targeted facade annotations and reuse passing results unless later edits affect them.
6. Review every remaining generic **consumer** read, including those outside the analyzer, against its supported inputs. All analyzed exceptions must have a local, specific reason. Check the final diff for stale guidance, redundant validators, changed domain contracts, broad suppressions and extra hot-path work. Run affected checks after corrections and request implementation review with the verification results.
