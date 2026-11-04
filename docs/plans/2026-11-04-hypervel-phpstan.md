# Hypervel PHPStan package

## Outcome and working state

Build `hypervel/phpstan` as a tracked Larastan port in `src/phpstan`, published through the normal component split. It supplies framework-aware type inference and diagnostics for Components, applications, third-party packages, and the private `packages/hypervel` and `packages/hypervel-dev` repositories. All reusable PHPStan extensions currently owned by Database, Foundation, Head and Scout move into this package. Ordinary source types remain in their owning components.

Worktree: `/home/binaryfire/workspace/contrib/hypervel/components-phpstan`; branch: `feature/phpstan-package`, based on `0.4`. This worktree has its own Composer installation and a copy of Components' `.env`. Do not share vendor directories between worktrees. Private monorepo changes belong on its `main`, under its own instructions.

This document specifies the final design and implementation work. Read it, the root monorepo `CLAUDE.md`, Components' `AGENTS.md`, and relevant package READMEs after compaction. Follow their approval, porting and testing rules. Planning authorizes no commits, pushes, publication, or instruction-file edits. The implementation needs the owner's approval after plan review.

Remaining work:

- Obtain owner implementation approval and the validation-contract decision specified below.
- Complete the implementation and verification sections below, keeping a single remaining-file checklist during porting.

## Design decisions

- Port Larastan's architecture, file organization, naming, algorithms and applicable tests; track its upstream. Use `cp`, read the complete copy, then edit, one file at a time in alphabetical order. Use `mv` for existing Hypervel extensions. Preserve upstream member order and useful comments. Fix inherited defects at their owning boundary.
- Boot the actual application; use Hypervel Testbench for packages. Retain runtime discovery of container bindings, macros, facade roots, authentication configuration and console definitions. Do not build a second static service-container simulator or require an alternative no-boot mode.
- Retain the upstream `extension.neon`, parameter schemas, PHPStan DI attributes and applicable rule switches. Select stricter Hypervel defaults. Keep settings for retained features, including paths and scanning/cache options; omit settings only when their feature is omitted. Do not implement anti-override mechanisms, non-ignorable policy errors, multiple strictness profiles, or a custom configuration language.
- Register `extension.neon` in `extra.phpstan.includes`. Document explicit inclusion and optional discovery through `phpstan/extension-installer`; installing the analyzer alone does not install or authorize that Composer plugin.
- Include maintained deprecation reporting through a required `phpstan/phpstan-deprecation-rules` dependency. Use normal `@deprecated Use X instead.` metadata for future Hypervel deprecations; do not build duplicate deprecation rules. Reporting covers known deprecated APIs from dependencies and PHP as well as Hypervel, subject to upstream metadata and deprecated-scope behavior.
- The public configuration catalog below is the source of truth for defaults. Preserve PHPStan's own level, paths, parallelism, baseline and suppression settings. PHPStan already defaults unmatched-ignore reporting to true; remove the three consumer false overrides after auditing their ignores rather than redundantly setting that default in the extension. Stronger defaults do not change the meaning of retained options.
- Package-owned diagnostics use stable `hypervel.*` identifiers, state the concrete problem and give the replacement. Rename Larastan-owned identifiers (`larastan.*` and `rules.modelAppends`) only; preserve PHPStan-owned identifiers emitted by reused offset/argument checks, such as `offsetAccess.notFound`. Keep ordinary suppressions for legitimate exceptions and analyzer limitations. New rule switches follow Larastan's boolean parameter/conditional-registration convention; do not add detailed tuning without a real requirement.
- Use PHPStan rules, type/reflection extensions, restricted-usage extensions and collectors for their intended jobs. Collectors can supply cross-file diagnostic facts after analysis; they cannot feed same-run type inference. Share an existing helper where needed, not a parallel inference engine.
- Runtime work is confined to genuine source typing/correctness fixes exposed by this port and changes needed to support the analyzer correctly. Configurable authoring-policy diagnostics can recommend modern APIs while legacy APIs remain supported. The separate legacy-removal todo is not permission to remove runtime APIs in this port; see the legacy-policy section.
- Shared component release versions apply. New mandatory defaults belong in a `0.x` minor release; false-positive corrections can ship in patches. Do not introduce an independent analyzer release train.

## Public configuration and identifiers

Use this catalog for `extension.neon`, parameter schemas, documentation and assertions; do not maintain a second defaults list in the plan. All boolean defaults apply to the normal extension include, and consumers may override them. Retained settings keep upstream meanings.

| Retained upstream parameter | Default |
|---|---|
| `noEnvCallsOutsideOfConfig` | `true` |
| `noModelMake` | `true` |
| `noImplicitQueryBuilderCall` | `false` |
| `noUnnecessaryCollectionCall` | `true` |
| `noUnnecessaryCollectionCallOnly` | `[]` |
| `noUnnecessaryCollectionCallExcept` | `[]` |
| `noUnnecessaryEnumerableToArrayCalls` | `true` |
| `squashedMigrationsPath` | `[]` |
| `databaseMigrationsPath` | `[]` |
| `disableMigrationScan` | `false` |
| `disableSchemaScan` | `false` |
| `configDirectories` | `[]` |
| `viewDirectories` | `[]` |
| `translationDirectories` | `[]` |
| `checkModelProperties` | `true` |
| `checkUnusedViews` | `false` |
| `checkMissingTranslations` | `true` |
| `checkModelAppends` | `true` |
| `checkModelMethodVisibility` | `true` |
| `checkConfigTypes` | `true` |
| `checkAuthCallsWhenInRequestScope` | `false` |
| `parseModelCastsMethod` | `true` |
| `enableMigrationCache` | `true` |
| `checkUniqueJobUniqueFor` | `false` |
| `checkUniqueJobUniqueId` | `true` |
| `noBatchedUniqueJob` | `true` |
| `checkJobSerializesModels` | `true` |
| `checkBatchedJobIsBatchable` | `true` |
| `checkBatchableJobChecksCancellation` | `false` |
| `checkDispatchInTransactionAfterCommit` | `true` |

Omit `checkOctaneCompatibility`, `generalizeEnvReturnType` and `redisDriver` with their unsupported/incorrect mechanisms. Keep the existing Hypervel `eloquentBuilderMacros` map, with its SoftDeletes and optional Scout entries pointing to the moved signature interfaces; it is extension metadata, not a rule toggle. Preserve PHPStan's normal built-in settings and adapt framework-specific bootstrap/early-termination entries to the real Hypervel symbols. Keep upstream's `universalObjectCratesClasses` entries as `Hypervel\Http\Request` and `Hypervel\Support\Optional`, whose contracts permit arbitrary property names. Set the built-in `reportIgnoresWithoutComments` to `true`; leave `reportUnmatchedIgnoredErrors` at PHPStan's default `true`.

| New Hypervel parameter | Default | Package-owned diagnostic identifier |
|---|---|---|
| `checkCancellationHandling` | `true` | `hypervel.cancellation.exactType` |
| `checkCancellationPrecedence` | `true` | `hypervel.cancellation.precedence` |
| `checkWorkerSharedState` | `true` | `hypervel.workerSharedState` |
| `checkStaticStateMutation` | `true` | `hypervel.staticStateMutation` |
| `checkUnboundedWorkerCaches` | `true` | `hypervel.unboundedWorkerCache` |
| `noRuntimeConfigMutation` | `true` | `hypervel.runtimeConfigMutation` |
| `noCoroutinesInTransactions` | `true` | `hypervel.coroutineInTransaction` |
| `noRawSleepCalls` | `true` | `hypervel.rawSleep` |
| `noLegacyModelScopes` | `true` | `hypervel.legacyModelScope` |
| `noLegacyModelProperties` | `true` | `hypervel.legacyModelProperty` |
| `noPipeSeparatedValidationRules` | `true` | `hypervel.pipeSeparatedValidationRules` |

The behavior and soundness limits of each new check are specified under Hypervel rules; a public name does not authorize speculative detection. Report one actionable diagnostic for the same underlying write rather than duplicating worker/static/config errors. No queue-property preference switch is defined before owner approval.

Map upstream-owned identifiers exactly as follows. Core PHPStan offset/argument/type identifiers are unchanged, including when emitted by a ported rule. The two omitted diagnostics have no new identifier.

| Upstream identifier | Hypervel identifier |
|---|---|
| `larastan.batchableJobChecksCancellation` | `hypervel.batchableJobChecksCancellation` |
| `larastan.batchedJobIsBatchable` | `hypervel.batchedJobIsBatchable` |
| `larastan.configCollection` | `hypervel.configCollection` |
| `larastan.console.undefinedArgument` | `hypervel.console.undefinedArgument` |
| `larastan.console.undefinedOption` | `hypervel.console.undefinedOption` |
| `larastan.deferrableServiceProvider.missingProvides` | Omitted with deferred-provider support |
| `larastan.dispatchInTransactionAfterCommit` | `hypervel.dispatchInTransactionAfterCommit` |
| `larastan.events.noConstructor` | `hypervel.events.noConstructor` |
| `larastan.jobSerializesModels` | `hypervel.jobSerializesModels` |
| `larastan.jobs.noConstructor` | `hypervel.jobs.noConstructor` |
| `larastan.missingTranslations` | `hypervel.missingTranslations` |
| `larastan.noAuthFacadeInRequestScope` | `hypervel.noAuthFacadeInRequestScope` |
| `larastan.noAuthHelperInRequestScope` | `hypervel.noAuthHelperInRequestScope` |
| `larastan.noBatchedUniqueJob` | `hypervel.noBatchedUniqueJob` |
| `larastan.noEnvCallsOutsideOfConfig` | `hypervel.noEnvCallsOutsideOfConfig` |
| `larastan.noImplicitQueryBuilderCall` | `hypervel.noImplicitQueryBuilderCall` |
| `larastan.noModelMake` | `hypervel.noModelMake` |
| `larastan.noPublicModelAccessorMethod` | `hypervel.noPublicModelAccessorMethod` |
| `larastan.noPublicModelScopeMethod` | `hypervel.noPublicModelScopeMethod` |
| `larastan.noUnnecessaryCollectionCall` | `hypervel.noUnnecessaryCollectionCall` |
| `larastan.octaneCompatibility` | Omitted with Octane support |
| `larastan.relationExistence` | `hypervel.relationExistence` |
| `larastan.uniqueJobUniqueFor` | `hypervel.uniqueJobUniqueFor` |
| `larastan.uniqueJobUniqueId` | `hypervel.uniqueJobUniqueId` |
| `larastan.unnecessaryEnumerableToArrayCall` | `hypervel.unnecessaryEnumerableToArrayCall` |
| `larastan.unusedViews` | `hypervel.unusedViews` |
| `larastan.uselessConstructs.value` | `hypervel.uselessConstructs.value` |
| `larastan.uselessConstructs.with` | `hypervel.uselessConstructs.with` |
| `rules.modelAppends` | `hypervel.modelAppends` |

## References and facts to preserve

Local upstream: `/home/binaryfire/workspace/references/larastan` (`3.x`). Read its current source at implementation time; it is read-only. The port tracks <https://github.com/larastan/larastan>. PHPStan source reference: `/home/binaryfire/workspace/references/phpstan-src`; the installed package is authoritative for actual API availability.

| Reference | Why it matters |
|---|---|
| Larastan `bootstrap.php`, `src/ApplicationResolver.php`, `src/Concerns/HasContainer.php` | App/Testbench startup and runtime reflection; adapt supported Hypervel boot APIs rather than recreating them. |
| Larastan `src/Support/ConfigParser.php` | Uses PHPStan scopes and `NodeScopeResolver`, including imports and preceding statements. Preserve the full parser, recursion protection, lazy evaluation and dependency tracking. |
| Larastan `src/Properties/{ModelPropertyHelper,MigrationHelper,SquashedMigrationHelper,MigrationCache}.php`, `src/SQL/` | Model schema inference and parsed-schema caching; parser/database support needs Hypervel adaptation. |
| Larastan `extension.neon`, `src/Rules/`, `src/LarastanStubFilesExtension.php`, `stubs/`, `tests/`, `e2e/` | Complete configuration, rule, stub and test inventory; do not infer coverage from documentation alone. |
| Components `docs/todo.md`, Static Analysis and Container sections of `AGENTS.md` | Cancellation examples, lifetime semantics, unmatched ignores, PHPStan version regression and intended eventual legacy removals. |
| `src/testbench/src/Foundation/{Application,Config}.php`, `src/testbench/src/Concerns/CreatesApplication.php` | `testbench.yaml`, `createFromConfig()`, discovery and the real per-process runtime skeleton. |
| `src/di/src/Bootstrap/GenerateProxies.php`, `src/di/src/Aop/ProxyManager.php` | Prepended proxy loader, original-source map, registry sealing, fingerprint reuse and atomic replacement. |
| `src/support/src/ServiceProvider.php`, `src/foundation/src/Bootstrap/LoadConfiguration.php` | Shallow top-level merging; named mergeable groups replace an entire matching entry. |
| `src/database/src/Eloquent/{Model,Builder}.php`, `src/database/src/Eloquent/Concerns/{HasAttributes,HasGlobalScopes}.php` | Scope registration can immediately call `extend()`; model construction, serialization, attributes and scope forwarding differ from simplistic substitutions. Local-scope discovery/dispatch lives on `Model`. |
| `src/translation/src/{Translator,FileLoader}.php` | JSON-first lookup, locale fallbacks, namespace overrides, extra paths and boot-added lines. |
| `src/foundation/config/queue.php`, `src/queue/src/{Queue,DatabaseQueue,CallQueuedHandler}.php`, `src/bus/src/UniqueLock.php` | After-commit defaults, transaction ownership, outbox and uniqueness behavior. |
| `src/coroutine/src/Coroutine.php`, `tests/Coroutine/CoroutineTest.php` | Cancellation chains, foreign/child boundaries and cancellation priority. |

PHPStan documentation: [extension testing](https://phpstan.org/developing-extensions/testing), [rules](https://phpstan.org/developing-extensions/rules), [restricted usage](https://phpstan.org/developing-extensions/restricted-usage-extensions), [collectors](https://phpstan.org/developing-extensions/collectors), [result-cache metadata](https://phpstan.org/developing-extensions/result-cache-meta-extensions), [ignores](https://phpstan.org/user-guide/ignoring-errors), [configuration](https://phpstan.org/config-reference). Confirm APIs against the selected supported version before using them.

## Package and repository wiring

1. Use `src/permission/{composer.json,README.md,LICENSE.md}` as the third-party-port skeleton, preserving Larastan's MIT attribution. Set `type: phpstan-extension`, namespace `Hypervel\PHPStan\`, PHP `^8.4`, normal Hypervel support links and branch alias. The README uses `Documentation: https://hypervel.org/docs/phpstan` for the page authored in `src/docs/phpstan.md`, states deliberate public differences, and ends with `Ported from: https://github.com/larastan/larastan`.
2. Preserve the upstream package-root layout: `bootstrap.php`, `extension.neon`, `src/`, and necessary `stubs/`. Use existing `Methods`, `Properties`, `Reflection`, `ReturnTypes`, `Rules`, `Support`, `Types`, `Collectors`, `SQL` and `Concerns` directories. Put new model rules at `Rules/` root beside upstream's model rules; use `Rules/Coroutine` and `Rules/Validation` for those genuinely new domains. Do not introduce separate engines for Hypervel additions.
3. Declare direct production dependencies of the analyzer in its own manifest, even though consumers install the analyzer with `--dev`. Require `phpstan/phpstan-deprecation-rules`: included deprecation reporting is part of the supported installation contract, not an unrelated suggested tool. Check the latest compatible stable release on Packagist before adding it; the verified 2.0.5 release requires PHPStan `^2.1.39`, compatible with the planned analyzer range. Translate required Illuminate components to their actual Hypervel owners. Do not depend on `hypervel/components`, the entire framework metapackage, Head, Scout, private packages or every optional integration. Suggest `hypervel/testbench` for package analysis. Guard optional integrations using existing reflection/stub-extension mechanisms; preserve an absent-component installation test.
4. Add root autoloading and `replace` metadata for `hypervel/phpstan`. The root replaces the split package, so it must declare the analyzer's external dependencies itself; add `phpstan/phpstan-deprecation-rules` to root `require-dev` with `composer require --dev` at the selected compatible constraint. Use Composer commands for other root dependency changes; subpackage manifests without lockfiles may be edited directly. Do not add a runtime service provider just to register PHPStan services. Register them in PHPStan's container.
5. Resolve the PHPStan version requirement before importing APIs: Larastan requires `^2.3.5`, while root, Database and Foundation pin `2.3.1` for the recorded `DataCollectionEloquentCast::set()` loop-narrowing regression. Check the current compatible stable release on Packagist, reproduce the narrowed `class-string<TData>` case, and run source/type fixtures against the candidate. Restore a supported range only after that regression passes. Do not loosen source types, add vendor patches or silently discard DI attributes to evade it. If still broken, follow the dependency-bug owner decision workflow with a concrete reproduction. Check compatible stable versions for every added dependency, including `iamcal/sql-parser`, optional phpMyAdmin support and any other SQL parser.
6. Components has no extension installer: in both `phpstan.neon.dist` and `phpstan.types.neon.dist`, replace the four old includes with explicit `src/phpstan/extension.neon` and `vendor/phpstan/phpstan-deprecation-rules/rules.neon` includes, and update type-fixture scan directories. Check each skeleton/private consumer's actual installer and plugin configuration before choosing its loading mode; use discovery or an explicit include for each extension without duplication. Keep source analysis and the dedicated `types/` configuration distinct; do not run normal source analysis over `tests/`.
7. Update the base skeleton, other maintained skeletons and Testbench package examples to install `hypervel/phpstan` as a dev dependency and use the standard setup. Inspect each current manifest and analysis script; preserve existing additional Carbon/Swoole support where still necessary.
8. `bin/split.sh` discovers packages from manifests. Ensure the new package passes existing publishing checks; do not introduce a second publishing system. Prepare the split repository/Packagist setup instructions. Creating external repositories, publishing and pushing require owner authorization at the actual publication step.

Example consumer configuration, without requiring the extension-installer plugin:

```neon
includes:
    - vendor/hypervel/phpstan/extension.neon
    - vendor/phpstan/phpstan-deprecation-rules/rules.neon

parameters:
    level: 5
    paths:
        - app
```

Use `src` for a package. Retain existing consumer levels unless a deliberate level change is separately justified; stricter package defaults do not require silently raising every project's PHPStan level.

With an enabled extension installer, both installed packages advertise their own NEON files through Composer metadata; omit their manual includes. Without the installer, retain both includes above (adjusting paths for a custom vendor directory). Do not add a package-owned config loader to hide the second include. Deprecation diagnostics keep upstream identifiers such as `method.deprecated`; they are not renamed to `hypervel.*`. Use identifier-specific ignores with reasons for justified cases. To disable the whole deprecation extension, remove its manual include or, for automatic discovery, add `phpstan/phpstan-deprecation-rules` to the root Composer `extra.phpstan/extension-installer.ignore` list and regenerate discovery through Composer. That list disables automatic loading only; it does not cancel a manual NEON include. Document these standard mechanisms from the [deprecation package](https://github.com/phpstan/phpstan-deprecation-rules) and [extension installer](https://github.com/phpstan/extension-installer), without introducing a duplicate Hypervel switch.

## Consolidate existing extensions

Move implementations and their reusable signatures, update namespaces/imports and consumers, then delete old registration files and metadata. Preserve existing behavior and tests while merging additional upstream capabilities. Never register competing resolvers for the same method to conceal a missing merge.

| Current files | Destination responsibility |
|---|---|
| Database `ForwardedBuilderMethodExtension`, `ForwardedModelMethodExtension`, `NamedScopeMethodExtension` | Central `Methods/`; merge Larastan builder/model/relation behavior and tests into one forwarding path. |
| Database `BuilderMacroResolver`, `ModelScopeMethodResolver`, `ModelScopeTypeResolver` | Central helpers beside the forwarding code; keep attributed/inherited scopes, generic substitution, variadics, named parameters and custom builders. |
| Database `ForwardedModelMethodReflection`, `ForwardedFluentMethodReflection`, `NamedScopeMethodReflection`, `ModelScopeParameterReflection` | Central `Reflection/`, keeping public parameter/return semantics. |
| Database `SoftDeletingMacros`; Scout `SearchableMacros` | Central analysis signatures; retain `eloquentBuilderMacros` metadata and optional trait detection. |
| Foundation `HelperFunctionReturnTypeExtension` | Merge into central helper-return extensions; preserve zero arguments versus explicit `null`, named arguments and unpacking for `response()`/`validator()`. |
| Head `WithHeadExtension`, `WithHeadMethodReflection`, `RoutingMacros` | Central optional routing-macro support without requiring Head. |

Remove each old `src/{database,foundation,head,scout}/extension.neon`, old `extra.phpstan.includes`, analysis-only namespace references and obsolete PHPStan dependency declarations where the component no longer directly uses them. Recheck `src/`, `tests/`, `types/`, skeletons, scripts, documentation, and private consumers for every moved symbol. Keep runtime interfaces and correct native/PHPDoc types in their component. Do not leave forwarding aliases or duplicate files merely to preserve the old internal layout.

## Bootstrap and runtime discovery

- Port upstream app discovery and error reporting. Honor an explicitly bootstrapped application where supported, ordinary `bootstrap/app.php`, Composer's actual vendor directory, and the package/Testbench path. Do not hardcode this worktree or the Components root into the published package.
- For package mode, use Hypervel's `Foundation\Config::loadFromYaml()` and `Foundation\Application::createFromConfig()`/existing bootstrap APIs. Preserve providers, aliases, config, root package discovery, workbench configuration and the package's real Composer autoloader. Hypervel's YAML key is `hypervel`, not `laravel`. Reuse Testbench's disposable runtime and vendor-link actions; do not copy Larastan's obsolete manual symlink fallback or create a second skeleton lifecycle.
- Boot once per PHPStan process, not per file or model. Parallel analysis boots independently in worker processes. Do not start HTTP/queue workers, run migrations, or probe backend services merely for analysis. Provider code can itself perform I/O; do not hide real boot failures or claim boot is free of side effects.
- Retain `BootstrapErrorHandler`'s clear CLI failure and nonzero exit. Adapt optional renderer integrations to supported Hypervel packages; do not add Whoops solely to preserve an unsupported Laravel integration. Do not continue with an empty container after a failed boot.
- Document the actual CLI boot requirements: Swoole (not OpenSwoole), `swoole.use_shortname=Off`, and Swoole built with OpenSSL and native cURL support. `Application::checkEnvironment()` already throws specific corrective messages. Preserve those messages through `BootstrapErrorHandler` and test that they remain visible; do not duplicate the checks or add exception-message matching to manufacture another tip.
- Preserve runtime macro/reflection discovery, facade resolution, class-string/string-key container returns, configured authentication user types and console argument/option definitions. Keep interfaces honest: an arbitrary interface variable does not acquire implementation-only methods merely because the container currently binds one implementation.
- Before changing AOP behavior, add a focused app/package subprocess fixture with an aspect, a class-map replacement, a provider registering a macro, and parallel PHPStan workers. Check original source diagnostics, inferred signatures and registration behavior. `GenerateProxies` seals registries and exposes `proxySources()`; `ProxyManager` reuses fingerprints and writes atomically. Parallel writes alone do not justify bypassing it. Preserve ordinary boot unless the fixture proves a conflict. Resolve a proven source-lookup conflict using the existing original-source mapping and PHPStan's supported facilities; no blanket disabling of aspects, custom AST rewriting, or speculative alternative class loader. If a supported API cannot preserve both semantics, bring the concrete failure and simplest alternatives to the owner before a capability change.

## Type inference and stubs

Port every applicable upstream inference feature, not just the rules in the next section. Audit `Methods`, `Properties`, `ReturnTypes`, `ClosureTypes`, `Reflection`, `Types`, collectors and stub files against the corresponding Hypervel API.

| Area | Required adaptation and coverage |
|---|---|
| Models | Native declarations and explicit PHPDoc take precedence appropriately; infer schema properties, relations, accessors, casts, separate read/write types, nullability, custom collections, factories and serialization shapes via `ModelSerializationDynamicMethodReturnTypeExtension`. Honor Hypervel attributes, visibility/appends and inheritance. Unknown schema knowledge must not erase declared properties or known relationships. |
| Builder/relation forwarding | Preserve model generics, custom builders, relation-specific fluent returns, attributed scopes, SoftDeletes/Scout and macro callbacks. Merge existing Hypervel capabilities with applicable upstream cases. |
| Helpers and collections | Preserve conditional argument behavior, `Arr` shapes, `filter()` narrowing, higher-order proxy callbacks and generic collections. Compare inherited annotations before adding a stub. |
| Authentication | Infer configured guard/provider user models and nullable results through actual auth APIs. Recognize real manager forwarding without advertising implementation methods on unrelated auth contracts. |
| Views and translations | Retain `view-string` and live view-finder namespaces. Keep translation typing and the adapted missing-key rule. The optional unused-view rule reports absent statically discovered usage, not proof that a view can be deleted. |
| Custom PHPDoc types | Port `model-property`, `relation-of`, `builder-of`, `collection-of`, `view-string`, their parsers, templates and applicable tests. Keep these analyzer-only annotations in stubs where adding them to framework source would require this dev package. |
| Dates and environment | Keep native/source `CarbonInterface` boundaries for configurable dates and exact immutable types for framework-owned values. Do not infer configurable dates from `get_class(now())`, or assume an `env()` default dictates the supplied environment value's type. |

Do not copy `ContractsMethodsExtension`, container ArrayAccess/dynamic-property support, Lumen paths, deferred-provider machinery, or unsupported Laravel integrations. Remove compatibility branches for old Laravel releases, but first merge applicable newer-version stub refinements into the single supported Hypervel surface. Deleting version directories without examining their overrides would lose features.

General type improvements go into source, with dedicated type fixtures and no runtime overhead. Confirmed examples include `Attribute<TGet,TSet>` and the conditional `FormRequest::validated()` result. `Enumerable::keyBy()` already declares a new-key template, and `Builder::make()`/`create()` already return `TModel`; do not duplicate these. Analyzer-only `model-property` refinements on Model/Builder (including `array<model-property<TModel>, mixed>` inputs) and `view-string` refinements on mail/view/route methods live in package stubs, not framework source. Adapt Redis stubs to Hypervel's phpredis-only pooled `RedisProxy` surface. Omit Predis stubs, Laravel's client-selection branches and the `redisDriver` parameter; there is no supported alternate transport to select.

Preserve upstream's Request and Optional `universalObjectCratesClasses` entries: `Request::__get()` resolves arbitrary input/route keys, and `Optional::__get()` forwards an arbitrary property name or returns null. These are supported dynamic APIs, not undefined-property mistakes. Do not add blanket crates for models or other classes to hide missing inference; use their dedicated reflection extensions and preserve explicit property types. Include the documented `$request->name` and Optional property access in typing coverage. Removing either dynamic API would require owner approval.

## Configuration and schema analysis

### Static configuration

Port `ConfigParser` in full, then adapt its discovery and merging. Boot supplies loaded provider identities and application paths, not a registry of package config files. `mergeConfigFrom()` records an opaque replay closure in `ConfigMutationTracker`, so inspect the loaded providers' source and statically resolve `mergeConfigFrom($path, $key)` arguments with PHPStan scope evaluation, including constant `__DIR__` expressions, inherited methods and supported helper calls. Preserve uncertainty for unresolved paths or dynamic control flow; do not invent a runtime path registry, inspect captured closure variables, or use post-analysis collectors to feed inference. Static expression analysis supplies config types, never the concrete values from the developer's `.env`.

```php
// config/feature.php
return ['enabled' => (bool) env('FEATURE_ENABLED', false)];

// Inferred boolean, regardless of this machine's FEATURE_ENABLED value.
$enabled = config('feature.enabled');
```

Cover helpers, facade and repository methods, typed getters and collection access. Respect explicit casts, imports, preceding assignments, nullable/union settings, defaults, package defaults and application overrides. Hypervel uses shallow merging; named groups declared by `mergeableOptions()` get one additional entry-merge level, with an application entry replacing its matching default entry whole. Honor registration order and supported custom providers. Reuse existing parser helpers rather than executing arbitrary config files a second time to get values. When a provider mutates config dynamically beyond the parser's knowledge, preserve uncertainty rather than inventing a fixed shape.

Retain `configDirectories`, `viewDirectories`, `translationDirectories`, migration/schema paths and associated upstream settings for retained features. Prefer boot-discovered paths by default; document exactly whether an explicit setting replaces or supplements discovery. Larastan's configured migration/schema paths replace its fallback paths; preserve that contract unless a separately justified adaptation changes it.

### Model schemas

Port migration aggregation, SQL parsing and `MigrationCache`. Cover MySQL, MariaDB, PostgreSQL and SQLite; do not present either upstream MySQL parser/converter as a PostgreSQL or SQLite implementation. No live database connection is required just to infer properties. Package schemas and schemas owned by other services can be supplied through the retained path settings.

Use a maintained parser before writing dialect handling. Packagist identifies [iamcal/sql-parser](https://packagist.org/packages/iamcal/sql-parser) as a MySQL schema parser and [phpmyadmin/sql-parser](https://packagist.org/packages/phpmyadmin/sql-parser) as MySQL-focused. For PostgreSQL, evaluate [calebdw/pg-schema-parser](https://packagist.org/packages/calebdw/pg-schema-parser) first: it has a stable release and a dedicated `PgDumpParser` for plain-text dumps, using `sad_spirit/pg_builder` for type grammar. Its [documented supported statements](https://github.com/calebdw/pg-schema-parser) include CREATE TABLE plus separate primary-key/default declarations; verify the actual supported Hypervel dump fixtures before selecting the dependency and adapting its output through Larastan's existing SQL boundary. Once it passes, declare it in the analyzer package's `require`, not `suggest`: PostgreSQL inference must be available in a standard dev install. A failed evaluation needs a concrete replacement decision, not silent loss of that support.

For SQLite, evaluate parser candidates against actual `SqliteSchemaState` output: quoted identifiers, defaults, generated columns, table constraints, STRICT and WITHOUT ROWID where emitted. [k-kinzal/sql-parser](https://packagist.org/packages/k-kinzal/sql-parser) exposes a SQLite grammar but currently lists only a development branch; [maghead/sqlite-parser](https://packagist.org/packages/maghead/sqlite-parser) has an old stable release and needs source/fixture verification before consideration. Do not silently add an unversioned dependency. If no suitable maintained parser passes, reach peer consensus on a bounded parser for the schema-producing statements emitted by the supported dump tools before implementation; preserve unknown schema state for unsupported constructs. No regex rewriting into MySQL syntax, executing dumps, or general-purpose SQL interpreter. Bring any unresolved dependency/coverage tradeoff to the owner rather than claiming full dialect support.

Track connection/table identity where it changes the result, schema changes across migrations, nullability, defaults, enums/casts and supported custom driver boundaries. If a dynamic migration, raw statement or unsupported custom-driver construct prevents a complete schema, preserve known declarations and avoid reporting an unproven missing column. Do not turn all unknown properties into `mixed` globally or suppress unrelated mistakes. Preserve upstream extension points instead of inventing a driver plugin platform.

## Result-cache correctness and analysis cost

- Retain PHPStan's `DependencyTracker`/`DeclarationDependencyTracker` use for configuration, migrations, schema dumps, views and translations. Track both changed files and directory membership so newly added/deleted files invalidate results. Keep declaration-based dependencies for reflection extensions and scope-based dependencies for return types/rules.
- Track runtime inputs explicitly; normal class dependencies alone do not capture registration code. For macros/mixins, track registered closure/method source files through reflection, plus registration providers and the mixin class itself. For container string keys, track loaded provider source, `bootstrap/app.php`, `bootstrap/providers.php`, Composer's installed metadata/root discovery configuration and package discovery cache, Testbench YAML, `.env` and the relevant pre-boot environment identity. Resolved concrete classes retain normal class dependencies. For console definitions, track command class files and `routes/console.php`. Auth configuration and view/translation namespace registrations inherit their config/provider/resource dependencies. Include files called by registration helpers where they supply a consumed fact; retain an explicit dependency at that boundary rather than assuming the analyzed caller references them. Compute shared inputs once per process, not per symbol.
- PHPStan restores its result cache in the main process before application bootstrap. A `ResultCacheMetaExtension` cannot assume a booted container. Use it only for global inputs available before boot, such as selected configuration/environment identity; use existing PHPStan facilities for per-file facts. Do not serialize the live container or eagerly boot it during cache restoration.
- Test a changed boot-derived return type with unchanged analyzed source, on a warm cache. A real provider/macro fixture must change a formerly valid call into an error without manually clearing the cache, and recover when the provider is corrected. Include a `mixin()` class referenced only by provider registration. Verify the same result with a cold run.
- Preserve the migration cache's locking, second read under lock and atomic replacement. Port upstream `MigrationCacheTest` and dependency tests, then add the changed-fingerprint regression: `getMTime()` has whole-second resolution, so ordinary edit-and-analyze loops can reuse a stale schema after two edits in one second. Keep mtime fixed explicitly in this regression instead of relying on test timing or sleeps. Use existing content fingerprints or a once-per-input `xxh128` digest and add a short WHY comment at the change so an upstream sync preserves it. Preserve upstream's timestamp-change invalidation alongside content changes so existing applicable tests remain meaningful. No new concurrency suite is needed for unchanged locking. Keep one parsed-schema cache, bounded in-process reflection caches and normal PHPStan temp storage; no extra persistent metadata service.
- Keep runtime source changes free of analysis-only request overhead. Parse lazily, reuse reflection/ASTs, and do not resolve every container service or model for every node. Use ordinary bounded correctness runs during development; get fresh owner confirmation that the machine is idle before any benchmark. Compare cold/warm analysis and representative runtime paths only when needed to assess a concrete cost.

## Upstream rule disposition

Copy retained rule files first, then adapt them. Retain their relevant toggles and tests. The table covers all 28 upstream rule classes; helpers such as `ModelRuleHelper` and `RelationExistenceHelper` follow their consumers.

| Rule | Final behavior |
|---|---|
| `ArrKeyExistenceRule` | Diagnose definitely absent `Arr::get`/`pull` keys; respect optional keys, defaults, dynamic shapes and actual argument binding. |
| `CheckDispatchArgumentTypesCompatibleWithClassConstructorRule` | Preserve job/event constructor checking, conditional dispatch, named arguments, unpacking and callable dispatch variants actually supported by Hypervel. |
| `ConfigCollectionRule` | Port against the adapted config parser and typed getters; do not forbid legitimate nullable/union `get()` calls. |
| `ConsoleCommand/UndefinedArgumentOrOptionRule` | Use booted command definitions, including signatures and inherited/configured definitions. |
| `ModelAppendsRule` | Support `#[Appends]` and currently supported property declarations. A database-backed attribute can be appended with a getter/class cast. Distinguish readable accessors from setter-only `Attribute` definitions; preserve custom serialization. |
| `ModelRelationDefaultsRule` | Validate `$with`/`$withCount` against real relations, nested paths, aliases and inherited definitions. |
| `NoEnvCallsOutsideOfConfigRule` | Retain the policy with actual package config paths and narrowly justified framework/bootstrap uses. Do not claim outside-config calls always return null. |
| `NoMissingTranslationsRule` | Reuse the booted translator, existing usage collectors and resource dependencies; do not rebuild lookup in a file scanner. Restrict default diagnostics to literal structured keys with a known group source and known standard file-backed resolution. See the translation predicate below. |
| `NoModelMakeRule` | Use the conservative declaration/runtime-metadata exclusions below, without interpreting arbitrary model boot code. Unknown or customized construction stays silent. |
| `NoPublicModelScopeAndAccessorRule` | Apply to the real Hypervel attributed-scope/accessor surface. Coordinate with the legacy-scope rule to avoid duplicate errors. Preserve inherited visibility and valid native methods. |
| `NoUnnecessaryCollectionCallRule` | Retain useful excessive-fetching detection, but require equivalent behavior for each recommendation. Limits, offsets, grouping, casts, accessors, callbacks and PHP/SQL comparison/null semantics can invalidate a rewrite. Keep upstream method-selection settings. |
| `NoUnnecessaryEnumerableToArrayCallsRule` | Recommend `all()` only for the normal collection behavior and values requiring no recursive `Arrayable` conversion. Cover overridden `toArray()`/`all()` and custom collection semantics; element type alone is insufficient. |
| `Queue/BatchedJobIsBatchableRule` | Preserve the runtime `Batchable` requirement and inherited traits. |
| `Queue/JobDispatchedInTransactionUsesAfterCommitRule` | Adapt as specified below; retain its switch. |
| `Queue/JobWithModelPropertyDeclaresSerializesModelsRule` | Include protected/private/nullable model state and supported model collections. Recognize inherited serialization and valid custom `__serialize()`/`__unserialize()` behavior. |
| `Queue/NoBatchedUniqueJobRule` | Cover the verified batch/bulk paths that bypass uniqueness acquisition, with an accurate explanation. Do not generalize to dispatch paths that acquire locks normally. |
| `Queue/UniqueJobDeclaresUniqueIdRule` | Require explicit identity for parameterized unique jobs; permit intentional class-wide identity through an explicit constant-returning method. Do not invent an identity or include secrets in diagnostics. |
| `RelationExistenceRule` | Preserve relation existence checking for loading/query APIs, including nested paths, selected columns, polymorphism and dynamic uncertainty. |
| `UselessConstructs/NoUselessValueFunctionCallsRule` | Resolve parameters correctly and preserve all argument evaluation effects; spread arguments are not ordinary positional values. |
| `UselessConstructs/NoUselessWithFunctionCallsRule` | Same argument/effect requirements; reordered named callback/value arguments are valid. |
| `DeferrableServiceProviderMissingProvidesRule` | Omit: no deferred-provider surface. |
| `NoAuthFacadeInRequestScopeRule`, `NoAuthHelperInRequestScopeRule` | Port as opt-in preferences, default off. Supported auth APIs remain valid. Preserve guard/custom request-resolver semantics; a variable named request does not prove an autofix equivalent, so do not offer that replacement automatically without such proof. |
| `NoImplicitQueryBuilderCallRule` | Port as opt-in, default off. `User::where()` remains the documented, supported form; an enabled preference may request explicit `query()` without changing framework semantics. |
| `OctaneCompatibilityRule` | Omit and replace with Hypervel-specific lifetime checks; ordinary container/config injection is supported. |
| `UnusedViewsRule` | Port as opt-in, default off. Report that no static usage was discovered, with the limitation for dynamic/package-exported views; never provide a deletion fix or claim this proves the view unused. |
| `Queue/BatchableJobChecksCancellationRule` | Port as opt-in, default off. Recognize `batching()`, the real batch's `cancelled()` and supported cancellation middleware through types, including inheritance. Describe detection of a recognized check, not proof that cancellation prevents all work. A coincidental method name is insufficient. |
| `Queue/UniqueJobDeclaresUniqueForRule` | Port as opt-in explicit-policy checking, default off. Recognize `#[UniqueFor]`, inherited methods and the supported property fallback. Do not require a positive duration, invent a timeout or claim locks are never released: normal completion/final failure releases them, and zero has backend-specific semantics. |

### Conservative construction and translation predicates

For `NoModelMakeRule`, skip models with a declared/inherited/trait `make()` override, custom constructor, custom builder (`#[UseEloquentBuilder]`, `$builder` or builder-factory override), overridden model/query construction, or custom lifecycle methods. Lifecycle exclusions include `boot`/`booting`/`booted`/`boot{Trait}`, `initialize{Trait}`, and Hypervel's `#[Boot]`/`#[Initialize]` methods; permit the unchanged base Model's own implementation. Skip `#[ScopedBy]` and scopes already recorded in the booted model registry (`getAllGlobalScopes()`), including registrations made by providers. Registered boot listeners/callbacks or other unknown construction customization also preclude an equivalence claim. Use existing reflection/runtime metadata, not a static boot interpreter or forced model instantiation solely for this rule. Include the attributed-hook and provider-registered-scope cases as negative fixtures, alongside a plain model as a positive case.

For `NoMissingTranslationsRule`, use the standard bound `FileLoader` and the booted translator's existing resolution APIs. Only analyze literal `group.item` or `namespace::group.item` keys with a known group file among relevant locales/paths. Track those lang directories and registration providers. `Translator::has()` is useful positive evidence, but false alone is not proof of absence: it returns false for a PHP/boot-added translation whose value equals its key, and intentionally bypasses missing-key callbacks. Confirm negative results with the known group data through existing loader/translator APIs; do not flag identical key/value translations or custom-resolution cases whose absence cannot be established. Preserve fallback locales, JSON full-key translations, namespace overrides and `addLines()` by reusing framework resolution, not copying it. Stay silent for custom loaders/translators where lookup can do external I/O or has unknown semantics. Add tests for explicit/dynamic locale arguments; extend collectors to carry known lookup arguments rather than assuming every call uses the boot locale. Preserve `translationDirectories` as a usable analysis input without mutating the application's translator. Comment the deliberate adaptation to upstream.

### Transaction dispatch

Use statically known configuration from the ported parser, plus statically known dispatch/job overrides. Never use a machine's `.env` value as proof that a deployment is safe or unsafe. Hypervel defaults `after_commit` to true except for `sync` and `database`; a same-connection database enqueue participates in the transaction, but another connection does not.

Cover direct and named `DB` connections, nested transaction callbacks, dispatch connections, job contracts/attributes/methods, explicit `afterCommit()`, `DB::afterCommit()` callbacks and outbox dispatch. Respect deliberate `beforeCommit()` use; this is an explicit supported escape hatch, not an error to undo. Do not flag synchronous execution as asynchronous visibility risk. For unknown connection selection or dynamic state, remain silent rather than guessing.

Required positive fixture: source config names a database queue connection different from the business transaction, with `after_commit: false`, no outbox and no explicit safe dispatch. Required negative fixtures: same-connection database, configured after-commit, explicit after-commit callback, outbox, sync, deliberate before-commit and environment-dependent connection selection. Verify queue transport/database connection names at their owning boundaries rather than equating their string identifiers.

### Verified regression examples

Keep these as focused tests, alongside all applicable upstream cases:

```php
value(...[static fn (): int => 42]);
with(...[21, static fn (int $number): int => $number * 2]);
with(callback: static fn (int $number): int => $number * 2, value: 21);
```

All three return `42`; upstream's raw positional-argument checks misdiagnose them. A limited query over three rows can return `1` from `limit(1)->get()->count()` and `3` from `limit(1)->count()`. An appended stored attribute with a getter can serialize correctly. A global scope's `extend()` can call `withAttributes()` immediately, making `Model::make()` and `new Model` differ even without a model-defined `make()` method. A collection subclass can intentionally override conversion behavior. Preserve these supported contracts.

## Hypervel rules

### Cancellation

Implement the concrete `docs/todo.md` patterns with PHPStan types and AST/control-flow information:

- An exact `catch (CanceledException)` that rethrows, followed by a broader catch that reports, retries, falls back or records an outcome; callback/I/O/cleanup failures can wrap cancellation before the broad catch sees it.
- `instanceof CanceledException` on a throwable not already narrowed by that exact catch, and `getPrevious() instanceof CanceledException` used to classify cancellation.
- Failure accumulation such as `$failure ??= $exception` where the value is the operation's retained failure and later cancellation would lose precedence.

Recommend `Coroutine::causedByCancellation()` and `Coroutine::preferCancellation()` at those sites. Use small shared expression/try-block helpers only where more than one rule needs them. Do not construct a general exception-flow interpreter or guess through arbitrary callbacks.

```php
catch (Throwable $exception) {
    if (Coroutine::causedByCancellation($exception)) {
        throw $exception;
    }

    // Existing ordinary-error handling remains here.
}

$failure = Coroutine::preferCancellation($failure, $exception);
```

Native-only Swoole/Hypervel channel, WaitGroup, join and Waiter operations can retain exact checks when they are the only work in the relevant try block. Do not exempt an entire file or method that also invokes user callbacks or cleanup. Preserve foreign/child ownership boundaries and cleanup-error reporting. Use `tests/Coroutine/CoroutineTest.php` cases for direct/wrapped/nested cancellation, Google's separate service cause, foreign and independent-child failures, and failure priority. Typed domain catches, failure observers and arbitrary ownership propagation remain runtime-review concerns, not purported static proofs. Legitimate exact checks use a local identifier suppression with a reason.

### Worker lifetimes and coroutine context

Use actual container semantics: unbound buildable classes auto-singleton unless intrinsically fresh; explicit binding, `Scoped`/`Singleton`, `Transient`, `SelfBuilding`, execution-scoped constructor attributes and contextual parameterized construction change the lifetime. Do not classify every mutable object as a singleton or every singleton mutation as unsafe.

Start from evidenced container candidates: registered `bind`/`singleton`/`scoped`/`instance` targets; statically resolved `make(X::class)`/`app(X::class)` calls; and dependencies injected into framework entry points actually constructed by the container (registered middleware/controllers/listeners/commands, after verifying their construction path). Track explicit and auto-singleton lifetimes separately from scoped/fresh/parameterized resolution. A class appearing only in `new X` is not a shared candidate. Use registration collectors for cross-file diagnostics and the booted container's existing metadata where available, honoring aliases, contextual bindings and mixed/unknown resolution paths. Do not recursively assume every referenced class is container-built.

1. Detect known request/operation state retained on worker-shared objects, including constructor capture and writes during known request/job handling. Start from the todo's property-write candidates, then classify their ownership using types, registration and the existing `Boot-only.`/`Tests only.` contracts. Intentional driver caches, registries and resolved config are valid worker state. Measure candidate findings and false positives across Components and both private packages before choosing an exemption mechanism. Reach peer consensus on the smallest adequate mechanism, using existing contracts and reasoned local suppressions where sufficient; a new public annotation requires an exact proposal and owner approval. Do not preselect per-write suppression or a whole-class exemption before measuring real cases.
2. Detect process-global configuration writes and static state mutation in known request execution contexts; distinguish boot registrations and `flushState()` cleanup. Detect direct request-derived cache keys only where the source establishes an unbounded worker-lifetime cache. Do not pretend to prove arbitrary taint propagation or boundedness across unknown calls.
3. Detect supported child-coroutine spawning APIs inside transaction callbacks when work is being moved off the transaction-owning coroutine. Explain that pooled database ownership belongs to the parent and the child does not inherit its transaction. Preserve established transaction-preserving test infrastructure and calls that do not actually spawn children.
4. Diagnose raw source `sleep()`/`usleep()` where the documented replacement is `Hypervel\Support\Sleep`. Preserve real-time test/infrastructure waits and other explicitly justified timing contracts.

Use collectors only for cross-file binding facts needed by diagnostics. Derive known runtime registrations through existing bootstrap/reflection facilities when available. Unknown ownership must not produce a confident leak claim. Evaluate candidates across Components and the private source before finalizing detection predicates; fix genuine violations and cover intentional patterns as negative fixtures. Do not add a separate policy framework, service registry, deep interprocedural data-flow engine or runtime instrumentation.

### Legacy authoring policy and safe fixes

Add configurable, default-enabled recommendations for the owner's modern conventions:

- Model `scope[A-Z]` methods -> protected `#[Scope]` methods. Match model inheritance/traits, not unrelated methods such as route `scopeBindings()` or Passport `scopeIds()`.
- Model subclass/consumer-trait configuration property declarations with complete attribute equivalents -> the corresponding attributes listed in `docs/todo.md`: table/connection/key/incrementing/timestamps/date format/fillable/guarded/hidden/visible/appends/touches/refreshes. The base model and its owning storage traits still declare runtime storage; do not diagnose those declarations. Preserve runtime setters/assignments and properties without equivalent attributes, including casts, defaults, eager relations and pagination. Report actual replacements, including config-driven values that require runtime initialization rather than an attribute constant.
- Pipe-separated validation lists -> arrays at verified validation entry points, FormRequest and Data rules. Preserve pipes inside individual regex rules and dynamic rule objects/closures.

Queue-property authoring diagnostics are a candidate requiring an owner policy decision, not an enabled rule in this plan. Before requesting approval, present the exact declaration-to-attribute mapping and dynamic exceptions (for example `Tries`, `Timeout`, `FailOnTimeout`, `Backoff`, `MaxExceptions`, `UniqueFor`, `Connection`, `Queue`). Do not infer blanket approval from the model-property policy or remove queue property support. If not approved for implementation, record the candidate in `docs/todo.md` with that mapping and its open policy questions; do not add dormant enforcement machinery.

These diagnostics enforce authoring policy; messages must not say a currently supported API is unavailable. Keep current legacy type inference/runtime behavior until its removal is explicitly approved. Do not automatically execute the much larger model/validation/queue runtime removal todo as part of importing an analyzer. A runtime removal needs a concrete, owner-approved change covering source, consumers, fixtures, error messages and porting documentation together; its cached boot checks must not add work per hydrated model. This boundary preserves supported APIs while delivering the requested static guidance now.

Retain PHPStan's autofix integration for verified local transformations, including unambiguous pipe lists and corrected `value()`/`with()` calls with preserved evaluation effects. Test fixes by reanalysis and runtime equivalence where relevant. Do not offer scope-renaming or model-property-to-attribute autofixes: cross-file direct calls and inherited precedence cannot be established by the local fix. Give an exact replacement in the diagnostic instead. Exclude `regex`/`not_regex` cases from mechanical pipe splitting. Audit other retained upstream fixes by the same criterion. Never run `--fix` automatically during ordinary analysis.

## Testing and implementation sequence

### Harness and fixture ownership

Port upstream rule/type/reflection/unit/integration tests under `tests/PHPStan`, preserving relative upstream placement and all applicable cases. Use PHPStan's `RuleTestCase` and `TypeInferenceTestCase` for rule and type-inference tests, as listed in `AGENTS.md` under Base classes. These analysis tests do not need Hypervel's coroutine wrapper. Runtime integration tests retain the appropriate Hypervel base class. No further test-base instruction edit or approval is required.

Dedicated rule fixtures intentionally contain invalid source and must be analyzed only by their harness with expected diagnostics. Preserve upstream `data/` placement where it supports syncing; put new Hypervel-only reusable support in `Fixtures/` per repo conventions. Port the upstream fixture app into package-owned test fixtures, loaded only for analyzer tests; reuse Testbench's disposable runtime instead of modifying its committed skeleton or always-loading package routes. Keep existing `types/` fixtures as public typing regressions and update moved references.

Keep type fixtures that intentionally exercise still-supported APIs. In `phpstan.types.neon.dist`, set `noLegacyModelScopes`, `noLegacyModelProperties` and `noPipeSeparatedValidationRules` to `false`, with one comment that these fixtures assert supported types rather than authoring policy. Also set `checkModelMethodVisibility` and `noUnnecessaryEnumerableToArrayCalls` to `false` in this harness: `CustomBuilderForwarding.php` deliberately declares public scopes, and Collection/LazyCollection fixtures assert conversion return types. These preferences retain their normal defaults in source/consumer analysis and their dedicated `RuleTestCase` coverage. Preserve type assertions; do not delete compatibility cases. For upstream always-registered diagnostics without a switch, use a local identifier suppression with a fixture-specific reason only at the intentional call (for example the existing `value('foo', 42)` and `with(new User)` helper fixtures). Do not invent public switches or a second rule-registration system solely for the harness.

Exclude mirrored analyzer `data/` directories and any intentionally preserved fixture-app files from `.php-cs-fixer.php` before running `lint:fix`; its current finder covers all tests and would rewrite deliberate syntax and error line numbers. Keep test classes and ordinary Hypervel support files formatted normally. Format new Hypervel-only fixtures before recording expected diagnostic lines, and rerun affected tests if a formatter changes those files. Convert bare `@phpstan-ignore-line`/`@phpstan-ignore-next-line` comments in ported source to accurate identifier-specific ignores with reasons, or fix the underlying type; the package itself is analyzed with the new ignore policy.

Do not add repository-housekeeping rules for test method title docblocks or missing test return types to this reusable package. General `tests/` analysis remains excluded; the separate formatting/convention work in the todo is unchanged. Follow the current conventions for all tests touched by this port.

PHPStan's DI container may live across tests while Hypervel cleanup flushes framework globals. Verify setup/teardown ordering and reset only package-owned static/runtime discovery caches that actually persist; use the standard cleanup registrar/subscriber. Do not blindly replace every upstream test base or require analyzer rule fixtures to execute inside coroutines. Framework runtime integration tests continue using the appropriate Hypervel base class.

### Work order

1. Inventory all upstream source, stubs, tests and current Hypervel extensions. Make one remaining-file checklist recording copy, move, merge or justified omission; do not duplicate it in this plan. Set up metadata, dependencies and the test harness.
2. Port bootstrap/Testbench/error reporting and verify app/package startup, optional components, AOP and worker behavior before relying on runtime discovery.
3. Move existing extensions and port the type engine, stub refinements, config/schema parsers and cache dependencies. Run each changed test class immediately. Keep public typing fixtures passing as registrations move.
4. Port retained rules with the corrections above, then add Hypervel rules and safe fixes. Include every applicable upstream test and the concrete regressions; do not weaken expected types/errors to get a green run.
5. Integrate Components, skeletons, private packages and affected private apps. Fix actual source defects and stale suppressions revealed by the new analysis; do not blanket-disable the defaults or generate a baseline to bury them.
6. Complete documentation, split/install tests, self-review and the assigned peer code review. Correct findings and rerun only affected checks. No implementation checkpoint permits commits or publication without owner authorization.

### Verification matrix

| Surface | Required checks |
|---|---|
| Rule behavior | Positive and negative fixtures, inheritance/traits, aliases, named/unpacked arguments, nullable/union types, dynamic unknowns, stable identifiers, toggles enabled/disabled and safe-fix output/reanalysis. |
| Type engine | `assertType` fixtures for model properties/accessors/relations, scopes/custom builders, factories/collections, auth, app/container keys, helpers, config, console, macros, views and public custom PHPDoc types; native contracts remain valid. |
| Current inference gaps | Baseline fixtures for `auth()->user()` (currently a false Factory `method.notFound`), `$model->relation`/`$model->column` (`property.notFound`), `app('cache')`, `$request->user()`, `Arr::get()` and `->map->getKey()` (currently mixed in the investigated examples), `collect([1, null])->filter()` (retains null), and a known model's `toArray()` (currently `array<mixed>`). Assert the expected precise type for a fully specified fixture while preserving uncertainty for dynamic/custom cases. |
| Schema/config | Real supported database dump/migration fixtures, custom-driver uncertainty, declared-property precedence, static env casts, nullable values, package/application merge ordering and typed getters. |
| Cache | Keep upstream dependency/cache tests. Add one warm-versus-cold regression per new or changed invalidation mechanism: file content, directory membership, provider input behind a runtime fact, macro/mixin source, a pre-boot global input, and same-second migration edits. Reuse existing coverage and combine fixtures where they exercise the same mechanism; do not multiply each input by every possible edit operation or add tests for unchanged locking. |
| Boot | Skeleton app, Components through Testbench, standalone package with `testbench.yaml`, custom vendor directory, runtime-requirement/failure diagnostics, AOP/class replacements and optional Head/Scout absent/present. Verify each process's Testbench copy is removed on normal exit and copies left by terminated workers are purged on the next run under the existing ownership rules. No accidental server start or committed-skeleton writes. |
| Consumer installation | A clean standalone Composer install of the split package with own dependencies; central and deprecation extensions each load exactly once with manual includes and with installer discovery. Reuse this fixture to assert a known deprecated call produces one upstream-identifier diagnostic. Required classes/stubs are present without Components root autoloading. Do not duplicate the dependency's own rule test suite. |
| Runtime behavior | Focused existing framework tests for every source typing/correctness change. No weakened contracts or runtime casts/branches solely for PHPStan. |
| Real projects | Components, base/maintained skeletons, both private monopackages and affected private apps. Adapt upstream's e2e workflow; public CI uses public/sanitized fixtures. Private sources and their baselines are never copied into the public repository. |

Use bounded sequential commands from the correct repository directory. Run `./vendor/bin/phpunit --no-progress <changed-test-class>` immediately after each test file. For multiple affected framework classes use `composer test:parallel -- --processes=4` with relevant selectors. Run `composer test:testbench` if Testbench changes. At a coherent completion boundary run `composer analyse` (source, `types/`, skeleton checks); it requires the skeleton's own dependencies. Run `composer lint:fix` before scheduled review. Do not run PHPStan on general `tests/` or pass source/test paths to `phpstan.types.neon.dist`. Keep parallelism inside the chosen tool, not overlapping verification commands. Leave full matrices to CI unless a concrete unresolved issue requires them locally.

For e2e baselines, preserve known intentional analyzer limitations with reasons and inspect baseline changes. Do not treat adding a new baseline entry as a fix for a demonstrated source defect. New Hypervel regression fixtures should have exact expected results.

## Consumer migration and final cleanup

- Resolve the deprecated validation-contract decision below with the owner before auditing those internal usages; do not let the general suppression guidance silently decide it.
- Audit `packages/hypervel`, `packages/hypervel-dev` and affected `apps/` under `/home/binaryfire/workspace/monorepo`. Both private package configs currently override unmatched-ignore reporting to false. Add the analyzer as a dev dependency with Composer, update source/type/generated analysis configs where applicable, supply package Testbench configuration, and remove obsolete direct-extension includes/scans.
- Private Composer repositories currently point at the main Components `src/*`. Validate against this worktree through a task-local Composer setup with its own installed dependencies, without permanently committing a worktree-specific path or disrupting another workload's vendor directory. Final private manifests reference the intended durable package source/version.
- Audit every suppression affected by improved inference or the new ignore policy in Components, both private packages, skeletons and affected apps. Convert every reason-less identifier ignore and every blanket line/next-line ignore in their analyzed paths to a source fix or an accurate identifier-specific ignore with a parenthesized reason. Do not limit this to newly ported files or errors that became unmatched. Remove obsolete inline ignores/global patterns and correct stale explanations such as claiming a boot-registered macro is unknowable. Retain narrowly justified limitations; new broad ignores require the normal owner approval.
- Audit deprecated-API usage in those same consumers, including calls to PHP, Symfony, Carbon and other vendor APIs, not just Hypervel declarations. Use the supported replacement when it preserves the contract. Keep a reasoned identifier-specific ignore when deprecated usage remains necessary, including compatibility with a still-supported public API. Do not remove supported APIs, strip truthful deprecation tags, mark unrelated code deprecated to silence errors, or add runtime workarounds merely to satisfy analysis. Dependency defects follow the normal owner decision workflow.
- Add `src/docs/phpstan.md` and navigation links, following existing Laravel-style documentation. Explain installation, app/Testbench boot, supported versions, configuration/defaults, optional integrations, custom PHPDoc types, diagnostic examples, suppressions, safe fixes, cache behavior and the distinction between authoring recommendations and runtime API removals. State in the page and release notes that adopting the documented setup diagnoses existing reason-less/blanket ignores and uses the included deprecation extension for known deprecated APIs across dependencies, including PHP, Symfony and Carbon. Explain its standard loading and opt-out mechanisms and upstream deprecated-scope behavior; do not promise detection of every runtime deprecation regardless of available metadata. Keep the package README minimal.
- Add a concise `src/docs/porting-from-laravel.md` entry: replace `larastan/larastan` with `hypervel/phpstan`, update the extension include, migrate Larastan-owned ignore identifiers (`larastan.*`, `rules.modelAppends`) to the documented `hypervel.*` mapping, and retain PHPStan core identifiers. Explain that retained parameter names remain compatible while defaults and omitted Octane/deferred-provider settings differ; link to the canonical PHPStan page.
- Update affected feature docs, skeleton setup, testing/type-fixture instructions, component manifests and package READMEs for centralized extension ownership. Amend `docs/todo.md` by removing completed items and the superseded instruction to keep extensions distributed. Preserve still-unimplemented runtime-removal and unrelated todos; update their references where this package now supplies checks. Never rewrite historical plans.
- Record intentionally omitted upstream features in the package README, a concise source comment at the natural registration/insertion point and `REMOVED:` comments at matching test locations, as required by the porting policy. Do not create empty placeholder classes for omitted features or annotate routine namespace changes and bug fixes as public API differences.
- Search the affected source, tests, types, active docs, manifests and scripts for obsolete namespaces, old extension paths, duplicated services, unused helper classes/settings, inaccurate compatibility claims and stale comments. Remove them together with their consumers. Keep upstream references/license attribution and historical plans intact.
- Before completion, review the final diff for source contract correctness, Laravel-style naming/ergonomics, coroutine ownership, analysis-only versus runtime cost, dependency isolation and unnecessary machinery. No custom service-container interpreter, universal lifetime annotation system, private PHPStan API patch or speculative registry should be needed.

### Owner decision: deprecated validation contracts

`Hypervel\Contracts\Validation\Rule` and `ImplicitRule` are deprecated, but remain part of the supported validation implementation and public contract, matching current Laravel. Built-in `Email`, `Enum`, `Can`, `Password`, `File` and `AnyOf` expose `passes()`/`message()` through these interfaces. `InvokableValidationRule` also adapts the modern `ValidationRule::validate()` API into that execution path. A removal affects Validator, RuleCompiler, ValidationRuleParser, ClosureValidationRule, ConditionalRules, Rule's builder/type declarations, ClonesCustomRules, and Data's RuleNormalizer and validation Rule attribute, including their type signatures and tests.

Before implementation, obtain an explicit owner decision. The recommendation for this analyzer port is to retain the existing runtime contracts, keep their deprecation tags and user-facing diagnostics, and authorize only the specific framework compatibility suppressions needed until the separately approved legacy migration. This is a public-API decision, not an automatic response to an analysis error. Removing just `implements Rule` while keeping the methods still breaks interface/type compatibility; retaining both interfaces does not remove the deprecation diagnostic. Do not wrap or rewrite the validation engine merely to hide that fact.

If the owner approves retention, add a named **Legacy validation rule contracts** entry under `docs/todo.md` Legacy removals, describing the eventual modern-contract conversion, affected adapters/built-ins/Data consumers, public `passes()`/`message()` and interface compatibility decisions, and ongoing upstream conversion requirements. Use the actual emitted deprecation identifier at each necessary framework boundary with one consistent reason: "Retains supported validation Rule/ImplicitRule compatibility; see docs/todo.md, Legacy validation rule contracts." Avoid a global ignore. Existing applications/private rules should use `ValidationRule` where their public contract permits migration. User code implementing the deprecated interfaces continues to receive diagnostics; do not exclude these interfaces in the analyzer.

If the owner instead approves removal in this work, first replace this decision section with a concrete source/consumer/test/documentation migration that preserves required behavior and explicitly records the approved API departures. Execute that migration before completing the consumer audit. Do not leave a confirmed runtime defect unresolved under either choice; deprecation alone does not establish one.

## Completion criteria

The central split package works in an application and a standalone Testbench-backed package, owns every reusable existing extension, retains the applicable Larastan engine/configuration/tests, provides the agreed Hypervel checks, and passes the verification matrix. Source contracts and supported APIs remain correct; all real findings introduced or exposed by the integration are fixed or taken through the explicit owner decision process. Old extension files, registrations, dead code and stale active documentation are gone. The plan's implementer obtains peer code-review signoff, summarizes the result and remaining owner-authorized external steps, and sends the user notification only after the terminal summary.
