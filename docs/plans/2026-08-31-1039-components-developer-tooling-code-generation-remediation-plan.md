# Developer Tooling and Code Generation Audit Remediation Plan

## Outcome

Resolve audit findings #104, #113–122, and #131–134 across Seeder, Wayfinder, Tinker, and facade documenter without adding request hot-path work or compatibility machinery. The finished code must:

- give every root seeding invocation fresh unbound seeders and its own `callOnce()` registry while honoring explicit application bindings;
- generate valid, accurate Wayfinder TypeScript and preserve original PHP action references rather than generated identifiers, or fail before writing partial output;
- register Tinker lazily, remove stale defaults, use public model APIs, and leave no test environment or file state behind;
- make facade generation faster without mutable-cache contamination, resolve trait/template/refinement types accurately, and fail predictably at its CLI boundary;
- preserve documented Laravel behavior except for the approved removal of Seeder's non-contractual protected static state and Hypervel-only cleanup method, plus Tinker's undocumented eager container key;
- leave `docs/todo.md`, package READMEs, and the Laravel porting guide unchanged, and remove this completed slice from the master audit ledger.

## Settled decisions and evidence

| Area | Decision |
|---|---|
| Seeder construction | `Container::build()` is not a valid freshness substitute because it bypasses aliases, bindings, instances, swaps, and resolving callbacks. `Seeder implements Transient` plus `make()` preserves those container contracts. |
| Seeder lifetime | `Transient` disables both unbound auto-singleton publication and constructor-derived execution scoping. That is correct: ordinary seeder resolution is fresh, while contextual constructor dependencies are still resolved for the current execution. Explicit bindings remain authoritative, including an application's deliberate shared binding. |
| `callOnce()` | The static registry currently survives independent root runs. The documented contract is once during the same seeding process, so ownership belongs to one root invocation and its nested seeders. |
| Seeder root ownership | `$root` is non-null only while an instance executes as a child. `call()` owns attachment and LIFO restoration because this is invocation state; `resolve()` owns construction and dependency propagation only. |
| Shared bound seeders | Sequential and re-entrant use must restore invocation state correctly. Concurrent invocation of one explicitly shared seeder is application-owned mutable-state sharing and does not justify locks or coroutine-scoped machinery in the framework. |
| Seeder compatibility | Laravel's protected static `$called` is an implementation detail with no documentation, test, or repository consumer as an extension seam. Hypervel's public `flushState()` exists only to clean that static. Their removal has been disclosed and accepted; no user-facing difference documentation is warranted. |
| Wayfinder duplicates | Validate the normalized generated route name, not only the raw name. Exact same-signature replacements already discarded by `RouteCollection` are not observable. Duplicate names are irrelevant under `--skip-routes`. |
| Tinker registration | Register `TinkerCommand::class`; the console's existing `freshCommandForRun()` clone boundary owns per-execution state. The class then stays unresolved during unrelated commands and resolves only when Tinker is requested. Do not retain `command.tinker`, add another lifetime marker, or document an internal binding change. |
| Facade caches | Caches live only for the one-shot CLI process and grow only with classes/docblocks in that invocation. Add no size caps or eviction. Measured over 47 facades in lint mode: 0.65 s to 0.37 s; docblock memoization saved about 0.12 s, import memoization 0.11 s, parser hoisting 0.04 s, and facade-name/source-class reuse about 0.05 s. Canonical-name caching saved about 0.01 s and is rejected as noise. |
| Cached PHPDoc ASTs | Parsed ASTs are immutable inputs. Build new method and parameter nodes before substituting resolved types; never mutate nodes returned from the memo. |
| Trait ownership | `ReflectionMethod::getDeclaringClass()` already identifies an inherited method's trait-using parent. Recurse only through traits-of-traits, deduplicate diamonds, and use source line containment for exact same-file ownership. |
| Refinements | Map the supported, finite PHPStan runtime-refinement vocabulary. Keep unknown identifiers as `mixed`; returning no type can silently remove a union member. Do not add `class-string-map` or `scalar` handling. |
| Documentation | Do not add Tinker attribution, a README differences section, a source omission comment, or a porting-guide entry. Do not alter existing facade-documenter attribution. Remove findings #104, #113–122, and #131–134 from the master audit plan once their replacement plan owns the work. |

## Reference baseline

- Current `examples/laravel/framework` still uses `make()` for nested seeders and a process-global static `callOnce()` registry. Hypervel must retain the binding-aware API while adapting the registry to its long-lived process.
- Current `examples/laravel/wayfinder` still uses the generated TypeScript method in the `@see` fallback and reflects parameterized middleware without stripping its suffix. These verified upstream defects must not be copied.
- Current `examples/laravel/tinker` uses `command.tinker` to support its deferred provider. Hypervel intentionally has no deferred-provider mechanism; class registration is the native lazy command path.
- Current `examples/laravel/facade-documenter` is the merge reference, but Hypervel's documenter already contains broader type handling and first-party facade validation. The cache, trait, refinement, and CLI fixes extend that current Hypervel design rather than replacing it with a second generator.
- None of the checked upstream default branches contains tests that already cover these fixes. Add focused Hypervel regressions rather than inventing an upstream-port provenance.

## Implementation

### 1. Give Seeder an intrinsic fresh lifetime and invocation-owned deduplication

Files:

- `src/database/src/Seeder.php`
- `src/testing/src/PHPUnit/AfterEachTestSubscriber.php`
- `tests/Database/DatabaseSeederTest.php`
- `tests/Database/SeedCommandTest.php`

Verified caller requiring no source change: `src/database/src/Console/Seeds/SeedCommand.php` already resolves the root with `make()` and applies the container and command before invocation.

Related caller, requiring no source change: `src/testbench/src/Foundation/Bootstrap/LoadMigrationsFromArray.php` invokes `db:seed` once per configured seeder class in one process. Each class must now receive an independent root invocation and therefore an independent `callOnce()` registry. Do not add a migration-backed fixture merely to restate the unit-level lifetime contract.

Make `Seeder` implement `Hypervel\Contracts\Container\Transient`. Use `make()` in `Seeder::resolve()` so nested resolution follows the same binding-aware path as the existing root resolution.

Replace the static registry with root-owned state:

```php
protected array $called = [];

private ?Seeder $root = null;
```

Keep `$root` initialized to `null`. Unlike the class's injected `$container` and `$command` properties, root ownership has a meaningful explicit empty state; do not convert it to a third uninitialized property checked with `isset()`.

`resolve()` must only construct the child and propagate the current container and command. Root attachment is invocation state rather than construction state, and keeping it out of this protected Laravel extension point prevents an override from breaking registry ownership.

In `call()`, attach the current root only for the child's invocation and restore the previous attachment in `finally`:

```php
$seeder = $this->resolve($class);
$root = $this->root ?? $this;
$previousRoot = $seeder->root;

try {
    $seeder->__invoke($parameters);
} finally {
    // Restore the previous root so a re-entered shared seeder keeps its outer attachment,
    // and so an instance later invoked as a root resets its own registry.
    $seeder->root = $previousRoot;
}

$root->called[] = $class;
```

Capturing and restoring the previous root is required for re-entrant shared seeders; blindly restoring `null` detaches an outer invocation that is still running. Keep the existing output timing and container/command propagation. Record a child only after successful completion, matching Laravel. `callOnce()` consults the root registry with a strict membership check. At the start of `__invoke()`, reset `$called` only when `$root === null`. A nested invocation must not reset the shared registry. This also makes repeated manual calls and explicitly bound root instances obey the per-invocation contract.

Delete `Seeder::flushState()`, its subscriber call, and the obsolete flush-specific test. Do not leave a no-op compatibility method or static mirror.

Coverage:

- unbound root and nested seeders are fresh;
- explicit bindings/instances are honored;
- dependencies and run parameters still inject through the container;
- a child called twice with `callOnce()` runs once in one root invocation;
- the same child runs again in a second independent invocation, including reuse of one explicitly bound root object;
- nested and container-less nested seeders share their root registry;
- separate roots do not share deduplication state;
- an explicitly bound child can later run as its own root without retaining the previous root's registry, including after its child invocation throws;
- re-entering the same explicitly bound child restores the outer root attachment before the outer invocation continues;
- command setup still applies container and command to the resolved root.

Do not add an execution-scoped-attribute seeder fixture. The container's `Transient` behavior is already tested at its owning boundary and that fixture would exercise an exotic composition rather than Seeder's contract.

### 2. Correct Wayfinder generation and validate named output atomically

Files:

- `src/wayfinder/src/GenerateCommand.php`
- `src/wayfinder/src/Route.php`
- `src/wayfinder/resources/docblock.blade.ts`
- `src/wayfinder/resources/method.blade.ts`
- `tests/Wayfinder/GenerateCommandTest.php`
- `tests/Wayfinder/RouteTest.php`
- `tests/Wayfinder/Fixtures/routes.php`
- `tests/Wayfinder/Fixtures/Controllers/OptionalController.php`
- `tests/Wayfinder/Fixtures/Middleware/UrlDefaultsMiddleware.php`
- `src/wayfinder/tests/GeneratedOutput.test.ts`
- `src/wayfinder/tests/OptionalController.test.ts`
- `src/wayfinder/tests/UrlDefaultsController.test.ts`
- gitignored generated fixtures under `src/wayfinder/tests/.generated/` (regenerated test artifacts, never committed)

#### Source links (#113)

Change the docblock target to:

```blade
$docblock_method ?? $original_method
```

The fallback is used by single controller and named-route renders. Multi-method rendering always supplies `docblock_method`. Preserve this invariant: every render site supplies `original_method`, and every multi-method include supplies the explicit PHP method.

Assert that existing controller methods use their original PHP method names rather than collision-renamed TypeScript identifiers. For deliberately invalid numeric fixture actions, assert the original action string rather than pretending a PHP method exists. Preserve invokable, named-route, and controller render behavior.

#### Parameterized middleware defaults (#114)

Before reflection, strip everything from the first `:` onward from each gathered middleware class string. Key `$urlDefaults` by that stripped class so variants such as `Middleware:tenant` and `Middleware:admin` share one reflection parse. Closures remain ignored.

Cover direct and alias-resolved middleware, parameterized variants, the plain class, and an absent class. Prove the generated parameter becomes optional and retains its backend default.

#### Duplicate generated names (#115)

When routes are being generated, validate duplicates immediately after normalized `Route` objects are built and before the helper or action files are written. Group by the generated `Route::name()` value, which captures normalization such as `foo::bar` to `namespaced.foo.bar`.

Add an internal `Route::originalName()` accessor over the wrapped route's unmodified name. Throw Symfony Console `InvalidArgumentException` with the generated name and every conflicting original name, HTTP method set, and URI. Do not generate overloads. Gate the validation behind `! $this->option('skip-routes')`; action-only generation does not consume route names and must remain valid.

Cover the accessor directly in `RouteTest`, including a raw name whose generated form is normalized, and cover its diagnostic use through the duplicate-name failure in `GenerateCommandTest`.

Tests must prove:

- different raw names that normalize to one generated key fail;
- ordinary duplicate names fail with both route descriptions;
- failure occurs before any helper/action/route output is written;
- `--skip-routes` still generates actions despite duplicate route names;
- ordinary namespaced routes continue to generate and typecheck;
- same-signature definitions already replaced inside `RouteCollection` are not claimed as detectable.

#### Optional root URLs (#116)

After optional parameter replacement and trailing-slash removal, floor an empty path to `/` before appending query parameters:

```ts
(normalizedPath || "/") + queryParams(options)
```

Add a `/{locale?}` fixture and cover omitted/present values plus a query string. Keep nested optional routes unchanged and avoid lookbehind syntax.

#### PHP integer defaults (#117)

Add one private helper shared by signed and unsigned literal parsing. Remove `_`, use `octdec()` only for `0o`/`0O`, and use `intval($literal, 0)` for decimal, legacy octal, hexadecimal, and binary forms. Preserve float, boolean, null/dynamic, unsupported-array, and neighboring-array behavior.

Cover upper/lower `0o`, separators, positive/negative signs, legacy octal, hex, binary, and decimal through generated runtime assertions. PHP CS Fixer normalizes committed numeric prefixes, so exercise `0O` through a temporary middleware PHP source file in `GenerateCommandTest`; keep that input separate from the generated output tree.

Run both PHP generator tests and Wayfinder's normal, cached-route, and typecheck suites. Generated fixtures are outputs, not hand-edited source.

### 3. Make Tinker lazy and clean up its stale state and defaults

Files:

- `src/tinker/src/TinkerServiceProvider.php`
- `src/tinker/src/TinkerCaster.php`
- `src/tinker/config/tinker.php`
- `tests/Tinker/TinkerServiceProviderTest.php`
- `tests/Tinker/TinkerCommandTest.php`
- `tests/Tinker/TinkerCasterTest.php`
- `tests/Console/ConsoleApplicationResolveTest.php`

Register `TinkerCommand::class` directly through `commands()` and remove the `command.tinker` singleton. Keep Tinker tests focused on package behavior: the legacy key is unbound; running an unrelated command leaves `$this->app->resolved(TinkerCommand::class)` false; requesting and executing `tinker` resolves the class and still works. Do not use command listing as a laziness assertion: Symfony's `Application::all()` resolves every command loader entry by design.

Add behavior-level console coverage for the existing clone boundary in `ConsoleApplicationResolveTest`: execute the same lazily cached command twice and prove the two executions receive distinct command objects rather than calling the protected helper directly. This belongs to Console because the behavior applies to every lazy command.

Remove `App\Nova` from the shipped `dont_alias` array. Assert the shipped/published default is empty and an application-provided exclusion still works. Keep optional `commands`, `alias`, and `dont_alias` omission behavior unchanged (#119).

Replace the bound closure in `TinkerCaster::castModel()` with `Model::getAppends()`, iterate the returned list directly, and delete the PHPStan suppression. Use a noncapturing `catch (Exception)` in application probing with one concise WHY comment: an unavailable individual probe is omitted from the presentation. Do not broaden it to `Throwable`.

Add model caster coverage for no appends, multiple appended accessors, hidden/visible prefixes, relation/attribute preservation, and accessor evaluation. Avoid database integration; these are model presentation semantics.

Repair `TinkerCommandTest` ownership:

1. Before `parent::setUp()`, capture `Env::get('COMPOSER_VENDOR_DIR')`, then `deleteMany()` and `flushRepository()` so `defineEnvironment()` can set the test value even after a prior immutable repository existed.
2. In teardown, delete and flush the test value, restore the original string value when present, and call `parent::tearDown()` in `finally`.
3. Replace `tempnam()` with a file inside a fresh `ParallelTesting::tempDir('TinkerCommandTest')` directory. Delete the directory in teardown even after an assertion or command failure.

This is required correctness: after the global subscriber flushes only the cached repository, a leaked backing value is treated as externally defined and a later repository `set()` is silently ignored.

Do not refactor Tinker's repeated config resolutions, add command lifetime markers, or change exception scope.

Retain `Hypervel\Console\Application::all()` and `run()`. The source comment above them and `ConsoleApplicationCompatibilityTest` document and pin why local typed declarations are required when Composer preloads an older untyped Symfony Console class.

### 4. Add safe, measured facade-documenter reuse (#131)

Primary files:

- `src/facade-documenter/facade.php`
- `tests/FacadeDocumenter/ImportResolutionTest.php`
- focused tests under `tests/FacadeDocumenter/`

Construct one `ParserConfig`, `ConstExprParser`, `TypeParser`, `PhpDocParser`, and `Lexer` lazily inside `parseDocblock()` and retain them in function-local static variables for the CLI process. Keep the parsed-docblock memo in that function, the class-import memo in `resolveClassImports()`, and the facade method-name memo in `conflictsWithFacade()`. Memoize parsed docblocks by their exact normalized parser input (`/** */` for an absent/empty comment) and class imports by class name. Change `ReflectionMethodDecorator` to retain its source `ReflectionClass` rather than constructing it repeatedly.

Do not cap these caches: their lifetime and keyspace are naturally owned by one finite CLI invocation. Do not cache canonical class resolution.

Make docblock reuse structurally immutable. `resolveDocMethods()` must construct fresh `MethodTagValueNode` and `MethodTagValueParameterNode` objects using all constructor fields while replacing only resolved parameter and return types. Reuse unmodified defaults and template nodes. No cached AST node may be mutated.

Tests must use two different facade contexts with identical `@method` text containing a `$this` return type and a `self` parameter type so a naive mutable memo deterministically contaminates the second result through both resolver branches. `$this` is not valid in an `@method` parameter position. Also cover class-import cache separation for multiple classes/start lines in one file. Keep timing assertions out of CI; rerun the documented 47-facade lint benchmark manually and record only a material regression.

### 5. Resolve the exact trait import owner (#132)

Extend `ReflectionClassDocblockContext` with `getStartLine(): false|int`, matching `ReflectionClass::getStartLine()`. Update `resolveImportSource()` to recursively traverse direct and nested traits, deduplicating trait names for diamonds, and select the candidate whose filename matches and whose source range contains the context start line. If the context filename or start line is `false`, fall back immediately; ignore any candidate whose filename, start line, or end line is `false`. Two internal reflections with `false` filenames must never count as an ownership match.

Do not walk parent classes and do not describe the visited set as cycle protection; PHP rejects circular trait use.

Extend `TraitImportSourceTest` for:

- a method imported from a trait used by another trait;
- multiple traits and a class in one file with conflicting short-name imports, proving filename and line-range ownership select the exact trait.

Keep the trait-name visited set because a diamond can otherwise repeat traversal, but do not add a test-only traversal counter or a behavioral test that would pass without deduplication. The declaring-class behavior was verified directly and does not need a redundant parent fixture.

### 6. Preserve supported PHPStan refinement and template types (#133)

Use three file-level classification constants for string, integer, and array refinements, with small predicates shared by `resolveDocblockTypes()` and `canPreserveConditionalTarget()`; do not introduce a registry or generic type system. The conditional-target guard must reject every name in all three classifications, plus the existing rewritten `class-string`, `key-of`, `value-of`, bounded `int`, `int-mask`, and `int-mask-of` forms. A conditional type must not preserve syntax that the resolver replaces with a different runtime type.

Map:

- `non-empty-string`, `non-falsy-string`, `truthy-string`, `literal-string`, `lowercase-string`, `numeric-string`, `uppercase-string`, `callable-string`, `interface-string`, `enum-string`, and `trait-string` to `string`;
- `positive-int`, `negative-int`, `non-positive-int`, `non-negative-int`, and `non-zero-int` to `int`;
- `int-mask<...>` and `int-mask-of<...>` to `int`;
- bare `list`/`non-empty-list`/`non-empty-array` to `array`;
- `list<T>`/`non-empty-list<T>` to `array<int, T>`;
- `non-empty-array<V>` to `array<V>` and `non-empty-array<K, V>` to `array<K, V>`.

Keep existing `class-string`, bounded `int`, `key-of`, and `value-of` behavior.

Before class/FQCN resolution of an identifier, inspect method-level `@template` and `@phpstan-template` declarations. A declared template resolves to its bound, or `mixed` when unbound, even if its name collides with a real class. No class-level template machinery is needed because no supported facade proxy exposes that surface.

Extend `PhpstanTagResolutionTest` and `GenericPreservationTest` with bare/generic/nested union/intersection forms, both template tags, bounded/unbounded and class-colliding templates, and every mapped refinement. Add conditional-target coverage driven from each classification and the separately rewritten generic forms, proving the fallback emits the resolved union rather than retaining an incompatible conditional. Assert emitted PHPDoc contains no invalid `mixed<...>` and does not degrade a precise native scalar to `mixed`.

### 7. Tighten facade-documenter CLI failures and package metadata (#134)

In `resolveDocParamType()`, catch only `ReflectionException` around `getPrototype()` and perform recursive parsing outside the catch. Delete the unreachable broad catches around `ReflectionClass` after `resolveClassConstantClass()` has already proved the class exists.

Validate CLI arguments against positional facade class names plus `--lint` and `--verbose` before any reflection or file writes. An unknown flag writes one concise diagnostic to STDERR and exits nonzero. Keep generated progress and lint differences on STDOUT. Move the exception handler and top-level `UnresolvableType` diagnostics to STDERR.

Add `ext-tokenizer` to `src/facade-documenter/composer.json` and to `PackageMetadataTest`. Do not alter the package README.

Coverage:

- an error raised while resolving a prototype parameter type is reported on STDERR, exits nonzero, and leaves the facade unchanged rather than being treated as a missing prototype; use an ordinary concrete override whose prototype type autoloads a fixture with a missing parent;
- a method with no prototype still falls back to its native type normally;
- unknown flags exit nonzero, use STDERR, and leave fixture files byte-identical;
- unresolvable-type warnings use STDERR while ordinary output remains on STDOUT;
- the split package's declared runtime dependencies match actual imports.

Run the standalone split-package install/autoload invocation manually after focused tests; do not add network/package-install machinery to PHPUnit merely to restate the metadata assertion.

### 8. Regenerate and verify the complete facade surface

After #131–134 are complete, restore the upstream structured return PHPDoc missing from `src/database/src/Schema/Builder.php::getForeignKeys()`, derive the complete first-party facade list using the same discovery rules as `FacadeDocblocksTest`, run the documenter once over the complete list, and inspect every generated diff. Commit only output changes caused by corrected type resolution; never hand-edit generated method lists.

Run:

- `tests/FacadeDocumenter/FacadeDocblocksTest.php` for current and parseable generated docblocks;
- the complete focused facade-documenter test directory;
- `composer analyse`, which includes `phpstan.types.neon.dist`;
- explicit review of `types/Routing/Route.php` and `types/Database/Connection.php`, which assert through generated facades.

### 9. Retire the completed slice from the master audit ledger

Remove findings #104, #113–122, and #131–134, including their slice-specific verification notes and resolved #119 disposition, from `docs/plans/2026-08-22-0604-components-04-audit-remediation-plan-codex.md`. This plan is the sole design record for the branch; the master plan remains a ledger of unresolved work and does not retain a completed-slice cross-reference.

## Verification sequence

Run focused checks immediately after each coherent section:

```bash
./vendor/bin/phpunit --no-progress tests/Database/DatabaseSeederTest.php
./vendor/bin/phpunit --no-progress tests/Database/SeedCommandTest.php

./vendor/bin/phpunit --no-progress tests/Wayfinder
(cd src/wayfinder && npm test)
(cd src/wayfinder && npm run test:cached)
(cd src/wayfinder && npm run typecheck)

./vendor/bin/phpunit --no-progress tests/Tinker
./vendor/bin/phpunit --no-progress tests/Console

./vendor/bin/phpunit --no-progress tests/FacadeDocumenter
```

Then run `composer fix` once. It owns formatting, PHPStan, the full parallel suite, Testbench verification, and the dogfood package suite. If it fails, correct with targeted checks and then run the failed entry plus every remaining `fix` script entry as required by `AGENTS.md`.

Review the final diff for:

- no remaining `Seeder::flushState()` or `command.tinker` references;
- no mutable access to cached PHPDoc nodes;
- no class-import cache key weaker than class identity;
- no duplicate pseudo-type lists outside the shared classification source;
- no generated-file edits unrelated to the corrected resolver;
- no README, porting-guide, or TODO churn beyond removing this completed slice from the master plan;
- no new request hot-path I/O, locks, context storage, version probes, or worker-lifetime unbounded state.

## Explicit non-goals

- No Wayfinder Vite scheduler or shell workaround; that TODO belongs to the external Vite plugin.
- No Boost installer or template implementation.
- No application-skeleton `composer dev` changes.
- No fixture-only workaround for `controllerMethodLineNumber() === 0` when a fixture names a nonexistent PHP method.
- No Tinker config-resolution micro-refactor or broad `Throwable` catch.
- No deliberately failing PHPUnit test or production/test seam solely to observe `tearDown()`; cleanup is exception-safe by construction and exercised by the real command tests.
- No compatibility alias/no-op for removed internal Seeder or Tinker container state.
- No facade cache counters, test-only production seams, caps, eviction, or speculative PHPStan types.
