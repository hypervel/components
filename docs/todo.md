# Source Implementation Gaps

## Release preparation

- Replace `psy/psysh` `dev-main` in the root and `src/tinker` manifests with the first stable release containing PsySH #951–#954. The latest stable release, v0.12.24, predates those changes.

## Authentication

- Create hypervel/react-starter-kit. Include the standard skeleton pieces that currently only exist as follow-ups: a `composer dev` script for running the Hypervel development server and frontend asset watcher together, plus explicit Hypervel Vite refresh paths instead of the Laravel plugin's `refresh: true` shortcut.

## Wayfinder

- Fix `@laravel/vite-plugin-wayfinder` generation scheduling upstream. Each plugin instance should capture its own hook context, normalize Windows path separators, parse a documented multiword command into an argument vector without shell expansion, serialize its own runs, collapse a burst into one follow-up run, recover after failure, and remain isolated from other plugin instances. Components should not carry a scheduler or shell workaround for plugin-owned behavior.

## Framework-wide

- Remove native Windows runtime support across all Hypervel packages, including standalone packages that do not depend on Swoole. Remove code paths and test expectations used only when running on native Windows, and direct Windows developers to WSL 2 in the platform docs. Preserve public Laravel-compatible helpers and signatures. Keep handling Windows-originated input wherever it is valid on a supported host.

- Audit the entire codebase for behavior missing from its contracts, designing them as complete greenfield framework interfaces rather than copying Laravel's omissions. Add methods every conforming implementation must provide, based on real consumers and implementation semantics; keep internal helpers and implementation- or driver-specific capabilities off general contracts. Update implementations, tests and public-contract documentation together.

- In a separate PR, make factory-created dates immutable by contract, removing the application-wide mutable opt-out from `Date` / `DateFactory`, `now()` and `today()`. Audit custom classes, factories, callbacks and consumers; preserve useful timezone, locale and test-clock customization and immutable subclasses. Keep explicit mutable conversion at third-party boundaries. Update runtime enforcement, return types, tests and docs together, then tighten `hypervel/phpstan` typing and discarded-date diagnostics wherever immutability is guaranteed; retain accurate handling of external mutable dates. Review the Laravel API departures before implementation.

- Once a released `nesbot/carbon` version fixes generated fluent return annotations to preserve subclasses, raise the applicable Carbon minimum through Composer and remove the redundant `@method static` overrides from `Hypervel\Support\CarbonImmutable` (`src/support/src/CarbonImmutable.php`). Carbon's `phpdoc.php::compileDoc()` currently emits concrete `CarbonImmutable`/`CarbonInterface` return types for these methods even though runtime preserves the calling class. Retain the existing overrides until a fixed release is verified; do not expand them into a duplicate of Carbon's complete method list. Keep `types/Support/CarbonImmutable.php` as dependency regression coverage, verify further subclasses as well, and remove `SupportCarbonImmutableTest::testMagicModifierMetadataMatchesRuntimeStaticType()` if no local overrides remain.


## Legacy removals

Remove older ways of doing things once a modern equivalent fully replaces them, so developers and LLMs writing Hypervel code have one correct way and get an immediate, clear error for the old one. These are deliberate departures from Laravel's API, not bug fixes. Make these changes in any private packages as well.

- Remove Eloquent model configuration properties that have attribute equivalents, and require `#[Scope]` instead of `scopeXxx` methods. Do all of them in one change, so there is one rule rather than a list of exceptions.
  - **Why.** Laravel's Eloquent docs already show only these attributes, but its code still supports both forms. A property and its attribute compete for the same instance property, and PHP inheritance cannot order one against the other, so the model has to decide which wins during construction. That has already caused a real bug: with a `$connection` property on a grandparent and `#[Connection]` on the parent, an empty child silently used the grandparent's database. The current fix for it cannot tell an untouched default from an assignment of the same value (see "Undo the inheritance fix" below). Without the property, none of this can happen.
  - **Replacements.** Before removing each property, confirm its attribute covers every supported use. If one does not, keep that property and record why here. Properties with no attribute, such as `$casts`, `$with`, `$withCount`, `$perPage` and `$attributes`, stay.

    | Remove | Use instead | Runtime API that stays |
    |---|---|---|
    | `$table` | `#[Table('name')]` | `setTable()`, overriding `getTable()` |
    | `$connection` | `#[Connection('name')]` | `setConnection()`, `on()`, overriding `getConnectionName()` |
    | `$primaryKey`, `$keyType` | `#[Table(key: ..., keyType: ...)]` | `setKeyName()`, `setKeyType()` |
    | `$incrementing` | `#[WithoutIncrementing]`, `#[Table(incrementing: ...)]` | `setIncrementing()` |
    | `$timestamps` | `#[WithoutTimestamps]`, `#[Table(timestamps: ...)]` | Assigning `$model->timestamps` at runtime, which `AsPivot` relies on, so only declaring a default is removed |
    | `$dateFormat` | `#[DateFormat]`, `#[Table(dateFormat: ...)]` | `setDateFormat()` |
    | `$fillable` | `#[Fillable]` | `fillable()`, `mergeFillable()` |
    | `$guarded` | `#[Guarded]`, `#[Unguarded]` | `guard()`, `mergeGuarded()` |
    | `$hidden`, `$visible` | `#[Hidden]`, `#[Visible]` | `setHidden()`, `setVisible()`, `makeHidden()`, `makeVisible()` |
    | `$appends` | `#[Appends]` | `setAppends()` |
    | `$touches` | `#[Touches]` | `setTouchedRelations()` |
    | `$refreshes` | `#[Refreshes]` | None today |
    | `scopeXxx()` methods | `#[Scope]` | Not applicable |

    The instance properties themselves stay, because the runtime APIs need somewhere to store values. Only declaring them in a child class or trait is removed. Attributes accept constant expressions only, so config-driven values such as package table names must be set after `parent::__construct()`, as `Role` and `Permission` already do, or come from an overridden getter.
  - **Failing loudly.** Silently ignoring a declared property would mean using the wrong table or database, so it must throw. Add one check on the model's boot path, so it runs once per class and costs nothing per hydrated model. Throw when `classPropertyDeclarer()` reports a class other than `Model` for a removed property, or when a model has a `scope[A-Z]` method, and name the replacement in the message, including the declared value where there is one, such as "App\Models\Post declares $table, which Hypervel doesn't support. Use #[Table('posts')] on the class instead. To choose a table at runtime, call setTable() after parent::__construct()." Do not use `final` properties instead: PHP's "Cannot override final property" error does not name the replacement, and an LLM may work around it in the constructor. An untyped `protected $table = 'posts';` never reaches this check, because PHP refuses to load the class first with "Type of Post::$table must be ?string", and that error cannot be caught or customized. Adding the type then triggers our message, so the LLM reaches the right answer on its second try. Accept that detour. The alternatives are worse: renaming the storage property so `Model` no longer declares `$table` sends `$this->table = 'x'` in a subclass through `__set`, where it becomes a model attribute and only fails on save; removing the native types from `Model` makes the code worse and gives typed declarations PHP's error instead; and adding a hint in `HandleExceptions::handleShutdown()` depends on matching PHP's wording. Convert framework classes first, since `Pivot` declares `$incrementing` and `$guarded`.
  - **Undo the inheritance fix.** Replace the table and connection branches in `Model::initializeModelAttributes()` with:

    ```php
    $this->table ??= $table?->name;
    $this->connection ??= static::resolveClassAttribute(ConnectionAttribute::class, 'name');
    ```

    Values assigned before `parent::__construct()` or in a trait initializer then always win, whether the attribute is direct or inherited. Today, a direct attribute overwrites them. Then:
    - Delete `classAttributeOverridesProperty()`, `$classAttributeOverrides`, its resets in `clearBootedModels()` and `flushState()`, and the `ReflectionProperty` import.
    - Delete the "assigning that property's exact default" entry from `src/database/README.md`.
    - Delete the "A custom `$primaryKey` inherited from a parent" sentence from `src/docs/eloquent.md`. A child's `#[Table(key: false)]` simply wins once the parent cannot declare `$primaryKey`.
    - In `DatabaseEloquentModelAttributesTest`, delete `testGrandchildKeepsTableAttributeOverride`, `testGrandchildKeepsConnectionAttributeOverride`, `testTableAttributeWithoutNamePreservesInheritedTable` and `testLocationAttributesInitializePropertiesWithoutDefaults`, plus the `GrandchildModelWithTableAttribute`, `GrandchildModelWithConnectionAttribute`, `ChildModelWithNamelessTableAttribute`, `ModelWithLocationProperties` and `ModelWithLocationAttributeOverrides` fixtures.
    - Keep `testInheritedLocationAttributesPreserveValuesAssignedDuringConstruction` as runtime-assignment coverage, but rename it, drop its grandparent-property case, and make its direct-attribute case expect the runtime values.
    - Remove the deleted caches from `testGuardAndTableMetadataCachesAreUsedAndCleared` and `DatabaseEloquentModelTest::testFlushStateRestoresStaticState`.
  - **Simplify the other initializers.** Once `$guarded` is gone, `guardConfiguration()` no longer needs to stop at a declaring class or read the property's default, and `classPropertyDeclarer()` is left with only the new boot check. Remove the `get_class_vars($class)['timestamps']` read in `isIgnoringTouch()`. Keep value checks such as `$this->primaryKey === 'id'` and `$this->timestamps === true`, because they protect values assigned before initialization. The merging in `initializeGuardsAttributes()` and `initializeHidesAttributes()` can stay.
  - **Nameless `Table` attributes.** The nearest `Table` attribute replaces a parent's entirely, because `resolveClassAttribute()` returns only one. So `#[Table(timestamps: false)]` on a child of `#[Table('flights')]` uses the name derived from the child's class, not `flights`. Keep this and document it in `src/docs/eloquent.md`: repeat the name, or use a dedicated attribute such as `WithoutTimestamps`, `WithoutIncrementing` or `DateFormat`, which leaves the parent's `Table` in place. Merging `Table` fields across classes would be a new rule.
  - **Scopes.** Delete `isLegacyScopeMethod()`, `$legacyScopeMethods` and its reset in `flushScopeCaches()`, simplify `hasNamedScope()` and `callNamedScope()`, and remove the legacy branch from `ModelScopeMethodResolver`. Convert `DatabaseNotification`, `HasRoles`, `HasPermissions` and Telescope's `EntryModel`, plus the `NamedScopes`, `CustomBuilderForwarding` and `SoftDeletes` type fixtures and the test fixtures. Other `scope[A-Z]` methods in `src/` are not model scopes and stay, including `Route::scopeBindings()`, `Passport::scopeIds()`, `StoredValueCache::scopeTokenKey()`, `ResolvesInheritedScopes::scopeExistsIn()` and the nested-set relation helpers.
  - **Consumers.** Convert the framework's models that keep `hypervel.legacyModelProperty` and `hypervel.legacyModelScope` ignores (about 20 files), removing the ignores, and all test fixtures. This is the largest part of the work: about 230 test files declare `$table` alone.
  - **Documentation and rules.**
    - Update `src/docs/eloquent.md` and `src/docs/migrations.md`.
    - `hypervel/phpstan`'s `NoLegacyModelPropertiesRule` and `NoLegacyModelScopesRule` then report declarations the runtime rejects. Report them whatever the equivalent attribute's value, drop their `noLegacyModelProperties`/`noLegacyModelScopes` switches and the `phpstan.types.neon.dist` overrides, and update the [Model Configuration](../src/docs/static-analysis.md#model-configuration) and [Model Scopes and Accessors](../src/docs/static-analysis.md#model-scopes-and-accessors) sections.
    - Rewrite the model part of "Inherited Properties" in `src/docs/porting-from-laravel.md`, including its "Common model properties to audit" list, and add the scope change.
    - Add a `Differences From Laravel` entry to `src/database/README.md`.
    - With owner approval, update `AGENTS.md`: the model examples under "Stricter typing", the `$table` note under "Helper class namespacing", the quick checklist's model-property step and "Use attributed local scopes".
  - **Future syncs.** Upstream tests keep using the old forms: about 120 Laravel test files declare `$table`. Add a rule to `AGENTS.md`, under "Approved unsupported features" or beside it, telling sync work to convert upstream fixtures to attributes and to remove upstream tests of property precedence with a `REMOVED:` comment, rather than porting the old forms back. This conversion is a permanent cost of every sync.
  - **Assess at the same time.** The `bootXxx` and `initializeXxx` trait method conventions have `#[Boot]` and `#[Initialize]` equivalents. Check whether they fully replace those conventions and add them to this removal if so.

## Testing

- Once PHPStan ships the [abstract property hook reflection fix](https://github.com/Roave/BetterReflection/issues/1599), add migration connection tests for a plain property that satisfies an abstract get hook. Check the literal on the declaring migration and on a migration that inherits it. [PHPStan report](https://github.com/phpstan/phpstan/issues/15792).

- Add native return types to the `test*` methods that lack them: about 5,600 methods in 490 files. `AGENTS.md` requires `: void` on test methods; a test that returns a value for `#[Depends]` takes its actual return type instead. Make the change as one approved sweep and add an automated check for missing test method return types.
- Integrate the resolution of [PHPUnit #7039](https://github.com/sebastianbergmann/phpunit/issues/7039) into `RunTestsInCoroutine` once PHPUnit 13.5 is released ([scheduled for December 4, 2026](https://github.com/sebastianbergmann/phpunit/blob/main/ChangeLog-13.5.md)); it adds `TestCase::suspendOutputBuffering()` and `resumeOutputBuffering()` ([PR #7040](https://github.com/sebastianbergmann/phpunit/pull/7040)). Follow the merged upstream design, including calling `parent::invokeTestMethod()` if capture is handled there. Add regression coverage for output expectations, flushed output and buffer cleanup; update the testing guidance and reassess output-related coroutine opt-outs. Lift the `~13.4.3` PHPUnit constraints in the root, Testbench and dogfood manifests, the testing package's suggestion and the `hypervel/hypervel` skeleton once Testbench's PHPUnit integration is verified on 13.5. Do not add a private-buffer adapter, maintained fork or ongoing Composer/vendor patches. Prefer supported subclass or method overrides; use AOP only as an absolute last resort. Never document a confirmed defect as an accepted limitation.

## AI and MCP

- Revisit conversation-history query plans when production evidence or newer database versions warrant it; this does not block the AI port. Reproduce with the stock migration, one oldest conversation containing 10,000 messages, then 999 other participants/conversations with 20 newer messages each. After updating statistics, inspect actual execution of `DatabaseConversationStore::latestConversationId()`, `getLatestConversationMessages(..., 100)` and the first/subsequent cursor pages. MySQL 9.5 and PostgreSQL chose reverse primary-key scans, examining 20,080 rows for 100 messages despite `(conversation_id, id)` being available; smaller targets at roughly 1% of the table used conversation indexes and did not scan other conversations. MariaDB 10.11 read history efficiently but filtered cursor IDs after seeking only the conversation, scanning preceding pages within it; SQLite 3.45 used both index bounds. The query shape matches Laravel's. Selecting IDs first, including through a bounded subquery joined back to messages, did not fix the MySQL/PostgreSQL plans. Include the similar latest-row queries in `pendingApprovalsFor()` and `pausedRowFor()` when investigating; their execution plans have not been measured. Test an index-derived scalar upper bound on message IDs with both oldest-only and interleaved conversations. A stored pointer needs maintenance across all supported writes; a stale low pointer would hide messages. Keep current queries and indexes unless a measured improvement preserves other workloads, configured/partitioned schemas and pagination behavior without unnecessary round trips or database-specific machinery.

## HTTP Client

- When a Swoole HTTP transport is available, compare it with cURL under concurrent Inertia SSR requests to a local server through the `inertia-ssr` connection.

## Documentation

- Publish reproducible Hypervel 0.4 benchmarks on a dedicated documentation page before linking them from the introduction. Record the framework, PHP, Swoole, and dependency versions; use the same hardware and load-generation conditions for every runtime; publish the benchmark applications and configuration; and include the raw results, collection date, and limitations. Do not reuse the Hypervel 0.3 results as current data. Once the page is published, add it to `src/docs/documentation.md` and link to it from the introduction.
- When the Hypervel 0.4 documentation is published, replace the versioned GitHub source links in both the `hypervel/components` and `hypervel/hypervel` READMEs with the corresponding hypervel.org documentation URLs. The documentation's `{{version}}` cross-links only resolve on the published site, so readers who follow the current links land on pages whose internal navigation is broken.

## Data

- Reconcile `hypervel/data` with the final `spatie/laravel-data` v5 release before Hypervel 0.4 is released. Compare the released source, tests, and documentation, and adopt worthwhile API, behavior, coverage, and documentation changes without replacing Hypervel's fixed creation engine, coroutine-safe state model, first-party framework integrations, or measured performance improvements with Laravel-specific machinery.

## TypeScript

- Port `spatie/typescript-transformer` as a general first-party Hypervel package, then add an optional Hypervel Data adapter that recognizes data classes and their existing mapping, Optional, lazy, and collection metadata. Keep TypeScript generation outside `hypervel/data`: the transformer must also support ordinary PHP classes and enums without making runtime data construction depend on filesystem discovery or code generation.

## Validation

- Remove pipe-separated validation rule strings in favor of arrays of rules across both `hypervel/validation` and `hypervel/data`, including Data's rule normalizer, denormalizer and `#[Rule]` handling. Update callers, tests and documentation together; preserve pipes inside individual regex patterns. `hypervel/phpstan`'s pipe-separated validation rules then report strings the runtime rejects: drop their `noPipeSeparatedValidationRules` switch and its `phpstan.types.neon.dist` override, and update the [Validation Rules](../src/docs/static-analysis.md#validation-rules) section.
- Adopt Brick Math 0.20's bounded parsing API for untrusted numeric validation inputs once the supported dependency graph permits it. Ramsey UUID currently limits Brick Math to 0.18, which has neither bounded parsing nor 0.20's parser-backtracking fix, so an intermediate upgrade would not address this issue. Preserve the configurable exponent-range behavior and cover delegated and compiled rules, oversized mantissas and exponents, malformed input, and `multiple_of`; do not add a local parsing workaround or disguise an incompatible Brick version with a Composer alias.

## Sentry

- Once a released Excimer includes `ExcimerProfiler::setSampleFilter()` ([Excimer change 1354126](https://gerrit.wikimedia.org/r/c/mediawiki/php/excimer/+/1354126)), compare native filtering with the transaction collector under concurrent load. Preserve request context ownership, idle intervals and SDK drop reporting.
- Once a Sentry PHP SDK release includes [sentry-php #2420](https://github.com/getsentry/sentry-php/pull/2420), require that release and register `Coroutine::causedByCancellation(...)` with `SentrySdk::setCancellationDetector()` in `SentryServiceProvider`. Verify that cancellation during a Sentry callback reaches the calling coroutine.

## Notifications

- Design a provider-agnostic first-party SMS notification API before adding an SMS provider. Keep Horizon's existing `Horizon::routeSmsNotificationsTo(...)`, but have Horizon target the generic channel and message contract rather than a vendor class; provider packages should adapt that contract to Vonage or other services. Decide routing, provider selection, message construction, per-message client overrides, and failure reporting, then implement the first adapter and update the notification and Horizon documentation, stubs, and Boost references. Keep mutable third-party SDK clients isolated per send—Vonage's client caches resources that mutate request and response state around yielding HTTP calls—while reusing only immutable configuration and the coroutine-safe transport. Add standalone package, provider, direct-construction, routing, failure, Horizon mail/Slack/SMS, and deterministic concurrent-send coverage. Do not add obsolete Nexmo names or compatibility aliases.
