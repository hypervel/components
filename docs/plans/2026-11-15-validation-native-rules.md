# Native validation rules and execution

## Purpose and boundaries

Give validation one typed description of database conditions, a stateless presence verifier, and efficient compilation without string round-trips. Preserve the familiar application APIs while replacing the legacy extension contracts that obstruct correctness and optimization. Include the related membership, numeric precision, rule lifetime and repeated-validation defects in this one validation-improvement PR.

The owner approved the API departures below. Further Laravel or tracked-package API departures, lost optimizations, or meaningful performance/scalability costs require a new evidence-backed peer discussion and owner approval. Do not preserve obsolete implementation choices merely to minimize churn. Do not add speculative operators, generic rule registries, compatibility adapters or reflection-based override detection.

Read root `CLAUDE.md`, the authoritative components `AGENTS.md`, this plan and the affected package READMEs after compaction, following their read rules. This plan owns the final design; implementation tracking should contain only remaining work and blockers.

### Execution and review

- Worktree: `components-validation`; branch: `improve/validation-native-rules`, created from `0.4` with independently installed dependencies.
- The plan is reviewed and committed, and the existing validation scope below is transferred and verified. The owner has authorized implementation with the same combined orchestrator/implementer and independent peer workflows.
- Implement coherent slices, self-review and verify each, and obtain peer code review at meaningful checkpoints. Make as many focused commits as reasonably useful, staging whole files only. Every commit needs a detailed body describing the problem, result, relevant design decisions and verification.
- After the complete validation implementation receives code-review signoff, commit it and stop to discuss benchmarking with the owner. Resolve that checkpoint before investigating PHPStan against the finished code on this branch. Reach peer consensus on analyzer proposals, then present them concisely to the owner and notify them. Do not implement unapproved new analyzer rules.
- Leave the sync branch unchanged during validation implementation. Removing its transferred scope and resuming publishing are subsequent coordinated work. Do not publish the validation PR yet.
- After the sync PR lands, merge updated `0.4` into this branch, resolve and verify the combined behavior, and publish a separate validation-improvement PR. If the initial PR exceeds 100 files, present the actual count and loss of free automatic CodeRabbit review for the required exception; do not split coherent work merely to meet the limit.

## Transferred scope and sync ownership

The complete existing validation fixes, tests and documentation have been transferred from `components-upstream-sync`, branch `upstream-sync-2026-11-14-2`. Their ownership includes:

- Validation source and unit/integration tests; Data's `ValidationAccumulator`, its tests and `DataValidationAsserter` fixture.
- The email-length sentence in `src/docs/validation.md`.
- Moving `Testbench\Foundation\UndefinedValue` to `Support\UndefinedValue`, with `ValidationData` and all three Testbench consumers: `Foundation/Env.php`, `Foundation/Application.php`, and `Concerns/CreatesApplication.php`.
- `Database/Query/Grammars/MariaDbGrammar.php` and `tests/Integration/Database/MariaDb/DatabaseMariaDbConnectionTest.php`.

The JSON:API porting-guide addition, unrelated Foundation changes, Testbench bcrypt assertions, CI edits and other batch work remain with the sync PR.

When later removing these changes from the sync PR, reverse only this ownership scope with new commits, preserving unrelated edits in shared files. Update the private sync queue so these upstream items remain assigned and unfinished until this PR lands; do not claim they landed in the sync PR.

The direct upstream port below has split ownership: only its validation portion transfers. Retain the routing portion's existing sync outcome when updating the queue; do not move or reopen the whole item.

### Upstream behavior included

The comparison source is Laravel framework `13.x` at `c980cb5827c6512c12ac44493d172a593b41299a`, with docs `13.x` at `eabbe57992fe98875329b546b2a13c7bfb707cc4`. Historical PRs explain the changes; the selected source and applicable tests define the ported behavior:

| Upstream | Required result |
|---|---|
| [#62347](https://github.com/laravel/framework/pull/62347) | Email validation rejects addresses longer than 254 bytes in both execution paths. |
| [#62402](https://github.com/laravel/framework/pull/62402) | An actual `__missing__` input is not confused with absence during wildcard extraction. |
| [Direct port ba9e21eb94](https://github.com/laravel/framework/commit/ba9e21eb9451bab33741be99239baf8ec4ff94ca) | Transfer its `UndefinedValue`/`ValidationData` source and validation tests with #62402. Its signed-URL routing source/tests remain assigned to the sync workflow. |
| [#62427](https://github.com/laravel/framework/pull/62427) | `not_regex` succeeds only on a successful non-match, not a regex execution error. |
| [#62420](https://github.com/laravel/framework/pull/62420) | Decimal matching avoids unnecessary backtracking and rejects the upstream exponent cases. |
| [#62425](https://github.com/laravel/framework/pull/62425), [#62442](https://github.com/laravel/framework/pull/62442), [#62459](https://github.com/laravel/framework/pull/62459) | Fluent literal values and repeated columns retain their meaning; repeated legacy string conditions are all applied. |
| [#62447](https://github.com/laravel/framework/pull/62447) | Ordinary integer/boolean presence bindings retain the intended database comparison semantics, while JSON booleans remain native. |

Retain all applicable upstream assertions when replacing their underlying implementation. A typed representation makes some upstream workarounds unnecessary; it does not remove their behavioral requirements.

## Approved public contract

Normal string rules and fluent application calls retain their names and usage: `Rule::exists()`, `unique()`, `where()`, `whereNot()`, null checks, membership checks, `ignore()` and `using()`.

The deliberate extension changes are:

- `queryConditions(): DatabaseConditions` returns typed ordered conditions, including explicit user callbacks, instead of a string/callback map.
- Presence verifiers receive a `PresenceQuery` rather than positional table/column/exclusion arguments plus encoded extras. Remove `setConnection()`, the now-empty `DatabasePresenceVerifierInterface`, and the connection argument to `Validator::getPresenceVerifier()`. Repoint the `validation.presence` container alias to `PresenceVerifierInterface`.
- Keep the protected condition-application hook named `addWhere`, with a native `DatabaseCondition` parameter, rather than silently ignoring old overrides after renaming it. Adapt `addConditions` and table selection to the typed/stateless boundary too. Old array-returning rules and old verifier/hook signatures must fail loudly under PHP's native checks.
- The protected candidate-normalization hook is `prepareValue(string $column, mixed $value): mixed`. Column context keeps JSON booleans visible to overrides and lets candidates share the condition normalizer without duplicate decisions or a forwarding layer. Explain the added argument beside the hook and in custom-verifier guidance.
- `Exists` and `Unique` expose `queryParameters()` as the canonical header override. Their `__toString()` methods become final and render that header and the declared conditions. No independent string-only header customization remains.
- `In`, `NotIn`, `ArrayKeys`, `Contains` and `DoesntContain` implement the existing `ParameterizedRule` contract. Their final `__toString()` methods render the canonical overridable `toArray()` export. Code inspecting `getRules()` receives these builder objects rather than their former strings.
- Base-Validator database declarations are read once per shared rule instance at validation-run start. A mutation between validator construction and `passes()`, or between runs, is reflected in the next run; an earlier check's mid-run mutation does not change the captured declaration. Runtime-dependent database logic belongs in `using()` callbacks. Custom Validator subclasses retain original rule objects and live database execution. `ParameterizedRule` declarations are captured once per shared instance in both Validator paths; extension hooks still receive the original objects.
- All four value-membership rules (`In`, `NotIn`, `Contains`, `DoesntContain`) share one comparison policy: booleans normalize through `0`/`1`, floats preserve precision through `Number::toString()` on both sides, other scalar/Stringable values use their existing string form, and null matches only null. Thus false/0/'0' match, true/1/'1' match, empty strings remain distinct, and numeric strings retain exact spelling. With `array`, NotIn rejects any listed member and accepts an empty array; scalar behavior and normal empty-field/nullable gating remain. ArrayKeys follows its existing key semantics. These behavior differences and the corresponding Data factory signatures are owner-approved.
- Data `Contains` and `DoesntContain` become object validation attributes. Widen their concrete `create()` and constructor input types as needed; retain `parameters()` as the owning accessor used by `getRule()`. Data `In::create()` and `NotIn::create()` accept nullable string parameters so normalized native declarations retain null.

Do not add compatibility forwarding layers for these approved changes. Document the actual adaptation for subclass authors and custom verifiers, not general warnings about normal validation usage.

## Typed database declarations

### Types and ownership

Place the new types directly in `Hypervel\Validation`, beside the existing compiler and verifier classes:

| Type | Responsibility and lifetime |
|---|---|
| `DatabaseCondition` | Readonly column, operator and operand. Represents one actual predicate, not a string token. |
| `DatabaseConditionOperator` | Exactly the six existing operations: `Equals`, `NotEquals`, `IsNull`, `IsNotNull`, `In`, `NotIn`. |
| `DatabaseConditions` | Ordered typed predicates and explicit callbacks. Owns legacy-extra decoding and plain condition data used for comparison/keying. |
| `DatabaseRulePlan` | Captured unresolved rule header and conditions used by compiled checks; worker-cacheable only when its complete contents are detached pure data. |
| `PresenceQuery` | Readonly resolved connection, collection, column, exclusion and conditions for one execution. Replaces the repeated metadata arrays and positional arguments; never stored in the worker plan cache. |

Native condition entry types reject malformed entries. A readonly container alone does not make contained arrays/references/objects immutable: detach membership arrays during run preparation, and never cache arbitrary caller objects or closures. Avoid a generic object freezer or deep serialization of executable state.

Use a variadic native entry boundary, for example:

```php
public function __construct(DatabaseCondition|Closure ...$conditions)
{
    $this->conditions = array_values($conditions);
}
```

Do not expose mutable collections as an immutable cache snapshot. Builder declarations may retain caller-provided membership inputs until run preparation; cached plans must not.

The resolved query has these fields (the supplied condition set is required, even when empty):

```php
public function __construct(
    public ?string $connection,
    public string $collection,
    public string $column,
    public DatabaseConditions $conditions,
    public int|string|null $excludeId = null,
    public ?string $idColumn = null,
) {
}
```

`queryParameters(): array` exports the existing ordered header: table/column for Exists; table/column/ignored ID/ID column for Unique. Keep native header values until the owning legacy boundary needs rendering or sentinel interpretation. The declaration remains unresolved where model or attribute context is needed.

### One condition representation

- Store `DatabaseRule` predicates structurally from the start. `whereIn`/`whereNotIn`, literal `NULL`/`NOT_NULL`/`!x`, and repeated columns are ordinary entries, not generated callbacks. Preserve every entry and its order.
- Null checks have their own operator. A fluent string `NULL` is a literal, not a null check; a fluent `!x` is a literal, not negation.
- Decode legacy string-rule extras once at the parsing boundary: `NULL`, `NOT_NULL`, leading `!`, and duplicate column/value pairs become typed entries. Do not retain a second decoder in the verifier or rebuild closures to represent known operators.
- Decode sentinel syntax only for string operands. Native array-form booleans, null and enums retain their types through parsing and Unique's field substitution; conditional comparison literals retain their existing string semantics.
- Preserve `queryCallbacks()` overrides by consuming their effective result when producing `queryConditions()`. An overridden empty `queryConditions()` must remain empty; stored predicates must not reappear through rendering, parsing or caching.
- `queryParameters()` owns the table/column/unique-exclusion header for both execution and rendering. Remove duplicate header extraction. Preserve model table/connection resolution, inferred attribute columns, ignored model keys and existing legacy header semantics.
- Preserve native null through dot and wildcard field substitution, including a Unique rule's absent ignored ID. Do not convert it to an empty-string exclusion or re-encode it as a legacy token.
- Normalize backed enums at their owning input boundary. A single backed enum passed to `whereIn`/`whereNotIn` is a one-element membership list; it must not reach `count()` as an enum object.
- Keep Arrayable membership values live until preparation, then export once. Cache only ordered primitive/null lists, preserving types and detaching references. Opaque membership inputs remain executable but uncacheable; do not convert query expressions or user objects into arbitrary cache data.
- Keep one shared normalization boundary for SQL equality/inequality operands and presence candidates: unwrap enums, preserve JSON-selector booleans, convert ordinary booleans through their integer form to strings and integers to strings, and leave floats/strings unchanged for the query builder. PDO owns float-to-text binding conversion; validation must not duplicate it. Membership comparisons retain the database query builder's supported behavior. Test float predicates and candidates together so constructor normalization cannot disagree with execution.

The owner approved preserving finite float precision in `PdoConnection::bindValues()` and `Connection::escape()`. Convert finite PDO operands through the shared Number helper before binding as `PDO::PARAM_STR`; retain native floats in `prepareBindings()` for custom transports. SQL escaping, increment/decrement amounts and schema defaults use the same conversion. QueryException, the Foundation exception renderer and Telescope must display the actual float text sent. Add concise source comments explaining the intentional departure from Laravel's implicit PHP conversion. This preserves `1.234567890123456` instead of rounding it to `'1.2345678901235'`, and preserves `0.1 + 0.2` as `'0.30000000000000004'`. Document explicit rounding for decimal comparisons, with concise README/porting-guide links. Preserve existing binding types and PDO non-finite behavior; verify stored precision and presence comparisons on all four supported drivers.

SQLite's update/delete row-identifier subqueries and PostgreSQL's shared row-identifier predicate select internal columns on a clone, preserving the reusable caller's columns and select bindings. Explain the clone beside each selection. Extend existing write-compilation tests with a selected expression/binding, and verify real arithmetic/default behavior on a reused builder across supported drivers.

The condition application is a direct match over the six operators using existing query-builder methods. Explicit user callbacks stay grouped as today and run at execution, never during key construction. There is no general predicate interpreter, dynamic operator registry, or new SQL abstraction.

## Stateless presence execution

Use these signatures:

```php
public function getCount(PresenceQuery $query, mixed $value): int;
public function getMultiCount(PresenceQuery $query, array $values): int;
// DatabasePresenceVerifier's existing batch capability:
public function getExistingValues(PresenceQuery $query, array $values): array;
```

Keep `getExistingValues` on the concrete database verifier; it is an optional batching capability, not a requirement of every custom presence verifier.

- Assemble `PresenceQuery` once from the effective rule declaration and current attribute. Use the same resolved object in ordinary validation, batch grouping, lookup keys and fallback execution.
- Remove the mutable connection fields from both database and precomputed verifiers. The default database verifier can remain a singleton because all invocation-specific state arrives as arguments.
- Preserve `useWritePdo()`, connection selection, exclusions and distinct-count semantics. Keep model/attribute resolution out of worker-cached declarations.
- Retain the existing database-proven batching algorithm, chunking, collation handling and fallback counts. A returned different representation is not proof of absence; do not replace its evidence-based logic with PHP equality or require every validation batch to use exactly one query.
- Derive `PrecomputedPresenceVerifier::lookupKey(PresenceQuery)` from the full normalized query shape: connection, collection, column, exclusion and ordered typed conditions. Do not stringify typed conditions or collide boolean `true` with string `'1'`.
- Ordinary candidate binding equivalence is distinct from typed condition identity. Keep integer/string candidate equivalence where SQL normalization makes them equivalent; float binding-identity keys use the same precise text as PDO. Unsupported candidate types retain correct individual fallback; adding typed conditions does not justify guessing database equality for new candidate types.
- For JSON-boolean candidate arrays, retain the grouped OR comparisons from the transferred correction; ordinary `whereIn` does not invoke the grammar's boolean comparison path. Reuse that behavior wherever the same candidate query is built rather than duplicating special handling.
- Carry the MariaDB binary JSON-selector/quoted-boolean fix. Do not change SQLite's existing JSON boolean extraction semantics to promise strict JSON type distinctions it does not provide. Rename the MariaDB test provider to `jsonBooleanComparisonsDataProvider` during implementation.

The shared-state defect is in Hypervel, not Swoole: the singleton connection was selected before overridable rule code ran, so nested validation or a coroutine yield could switch the outer query's database. A stateless query fixes both cases without cloning services, context stacks or locks.

## Preparation, compilation and caching

### Capture once, keep hits cheap

Base `Validator::compileRules()` performs one preparation pass per attribute's rule list. Each validation run owns a temporary `WeakMap` so shared wildcard builders are exported once, with cleanup in `finally` even when compilation fails. Keep the exported form, parsed form and cache eligibility of each `ParameterizedRule` declaration together, so later attributes and metadata scans do not repeat preparation. Base database entries retain their captured plan and plain cache description; custom Validator database declarations remain live. The compiler returns prepared rules and a nullable cache key; no extra DTO/service is needed.

1. Read each database rule's effective header/conditions once per shared instance per run, including rule subclasses. Read each `ParameterizedRule::toArray()` once per shared instance too. Use the captured result for keying, metadata and execution. Detach parameter-array references before memoizing a structured export so custom Validators also retain the captured values; do not freeze opaque objects or callbacks.
2. Keep ordinary strings raw when producing the key. A string-only cache hit must not parse rules or allocate database-condition objects. On a miss, parse each string once and pass the parsed header/extras to plan construction.
3. Compute one key from plain data. Change `RulePlanCache::get`/`put` to take that key; neither may call rule getters again. Avoid serialized strings embedded inside another serialization.
4. Prepare membership arrays once: export Arrayables, unwrap enums and detach element references together, including predicates alongside callbacks in an uncacheable plan. Key construction and batch lookups then inspect these plain values without remapping them. On a hit, use the cached plan; on a miss, detach header and generic array-rule parameter references while building the plan. Do not copy a complete plan merely to discover whether a cached plan exists.
5. Uncacheable captured declarations still execute correctly for this run. Explicit callbacks and opaque objects keep their execution-time behavior; do not deep-freeze them. Closures, query-expression objects and arbitrary mutable values never enter the worker cache. A cache eligibility decision must inspect headers as well as conditions.

Retain the bounded LRU and existing cleanup registration. Do not remove subclass caching as a shortcut: subclasses now follow the approved declarative contract, and pure exported declarations qualify. No class-name guard or parameter-count heuristic is needed. Custom Validator subclasses continue through `compileAllDelegated`, without worker caching or substitution of their original rule objects.

Custom `Rule`/`ValidationRule` execution takes precedence when a subclass also inherits `ParameterizedRule`. Share that declaration predicate between parsing, preparation, metadata and Data normalization/comparison. Do not export or merge executable custom rules by their displayed parameters; retain their original callbacks and identity. Apply the same identity rule to executable Exists/Unique subclasses, preserving the established native Enum comparison and Data attribute mappings.

### Parameters and ordered execution

Keep the three distinct tiers explicit:

| Parameters | Cache eligibility | Compilation |
|---|---|---|
| Primitive scalar values | Eligible | Inline where the existing check supports it. |
| Scalars plus null | Eligible | Delegate null-bearing checks individually; neighboring safe checks can still inline. |
| Objects, nested arbitrary arrays or resources | Ineligible | Preserve whole-attribute ordered delegation so compile-time casts cannot execute user code early. |

Database membership lists have their dedicated typed representation; they are not a reason to accept arbitrary nested generic rule parameters. A delegated `['in', null]` matches null under the approved membership policy, rather than using the inline string cast to `''`.

Keep parser preparation from prematurely stringifying Exists/Unique. Remove the subclass `2/4` header-count check, generated callback comparison workarounds and duplicate parsing once the typed path owns those responsibilities. Keep supported generic string parsing and callback rules; their existence is not stale code.

## Other validation improvements

### Membership builders and Data

- `In`, `NotIn`, `Contains` and `DoesntContain` unwrap enums and use one shared value normalizer: null remains null, booleans become '0'/'1', floats use `Number::toString()` on both sides, and other supported values use their existing string form. Apply that same normalizer in compiled and delegated scalar/array checks; replace `array_diff` so it cannot merge false or null into an empty string. Stringable values normalize once in declarations. Non-Stringable objects and nested arrays do not match scalar/null input requirements.
- Build membership lookups once rather than scanning the input repeatedly for each parameter. With `array`, In requires every item to be allowed and NotIn rejects any forbidden item; do not implement the latter by negating the whole In check. Non-scalar array elements do not match scalar/null parameters. `ArrayKeys` keeps its enum/string conversion because it checks PHP keys, not values.
- Keep explicit string rendering available, with existing quoting/escaping behavior. Rendering is not the validation transport and cannot represent every typed value losslessly.
- Move Data's builder-specific wrapping ahead of generic `ParameterizedRule` conversion. Wrap In/NotIn/Contains/DoesntContain in their matching object attributes, retaining the actual builder and its overrides. Wrap ArrayKeys directly in the existing `Rule` attribute because it has no dedicated Data attribute/factory keyword; do not export it and throw just to rediscover that. Remove the superseded match branches. This avoids export/reconstruction and premature declaration capture. Use a validation attribute's declared keyword for presence inference in both compiler branches; inspect actual declarations for raw rules and general rule wrappers.
- Keep direct factory conversion correct too: `ValidationRuleFactory::create()` accepts `ParameterizedRule`, so all four concrete factories must accept normalized string/null exports.
- Resolve Data external references when compiling the attribute, preserving their native values until membership normalization. Keep the common attribute contract unchanged; widen only the four concrete membership factories. Retain useful public `parameters()` by using it, rather than retaining dead compatibility code.
- Data accumulator equality compares the same typed declarations, with ordered conditions and strict primitive distinctions. Compare callbacks by identity; never equate different closures because their printed rules match. Avoid executing runtime callbacks while comparing rules.

Verified failures to prevent: forbidden integers bypassing checks through form/JSON type differences; true satisfying arbitrary required names; false matching empty-string allowlists; false declarations rejecting form '0'; and null being merged with empty strings. Cover shared semantics in the validation tests; keep Data tests focused on attribute/factory wiring, preserved declarations and reference resolution.

### Numeric precision

Centralize numeric conversion in an internal `Support\Number::toString(int|float|string): string` helper shared by validation and decimal casts. Extract the existing finite-float formatting approach from `Rules/Concerns/FormatsParameters` and Data's `RuleDenormalizer`; do not add a dependency or modify process-wide `precision`/`serialize_precision` settings. JSON's shortest round-trip formatting uses PHP's default `serialize_precision=-1`; do not claim independence from that setting or implement a custom float formatter.

- Preserve integer/string spelling without a JSON conversion. Convert finite floats before Brick Math; it accepts strings/ints, not native floats. Return explicit non-finite spellings so existing math boundaries reject them without PHP's NAN-cast warning. Preserve exponent bounds and exception behavior.
- Apply this at every affected numeric boundary: Numeric min/max/between/multiple-of rendering, compiled size thresholds and values, and delegated size/min/max/between/multiple-of and comparison-rule operands. Trace sibling BigNumber/BigDecimal calls; do not fix only a builder's output.
- Keep `trim()` and numeric size/exponent-policy input types unchanged. Convert values after these hooks, at the math boundary. Share float-only parameter preparation in one private helper, leaving ints and supported Brick objects intact. The exponent guard may format its local inspection string, but callbacks still receive the original value. Format comparison-message values consistently too.
- Convert native floats before field substitution, retaining null and exact string references. Apply the same conversion to Unique's exported ignored ID and resolved float exclusions before integer/sentinel handling, so fluent, array and field-reference forms agree with PDO binding. Convert conditional literal-tail floats without changing their other scalar semantics.
- Keep size/count semantics for strings, arrays and files; do not treat every size operand as an arbitrary-precision number or remove integer fast paths.
- Numeric inputs to integer, decimal-place, digit-count, regex, alpha-num/dash, prefix/suffix and date checks use the same precise text in compiled and delegated execution. Preserve existing type gates, string spelling, whitespace and strict-integer behavior; do not reuse the trimming math helper. Formatted date comparisons must parse the same text as `date_format`. Normalize float siblings for `distinct:ignore_case`. String-length size checks use that text when numeric semantics are absent; numeric size hooks still return the original number. Keep common string/int inline checks on their direct cast path.
- Format float message inputs before custom-value lookup, translation-key construction and display; preserve precise dependent-rule parameters in messages and `failed()`. Handle arrays before the lookup using the existing `array` display result, so configured value labels cannot trigger an invalid array-key error.
- Reuse the helper in conditional formatting, Data rule denormalization, Foundation/Eloquent decimal casts, and the approved database conversion points above. Keep decimal scale rounding and exponent guards.
- Value membership uses the same float conversion on both sides, preserving self-matches without equating distinct rounded values. ArrayKeys retains its PHP key semantics.

Regression example: `Rule::numeric()->max(1.234567890123456)` must reject `'1.23456789012348'`; the former string cast rounded the bound to `1.2345678901235`. Cover compiled and custom-Validator/delegated execution, native float parameters, conditional literals, and an exponent callback receiving its original float. Extend the existing decimal-cast providers with float rounding cases; do not add tests that merely mirror the helper.

### Caller-owned lifetimes and repeated runs

- Implement `Transient` on intrinsically caller-owned mutable rule families, checking their subclasses. The concrete families include ArrayRule, Confirmed, CurrentPassword, Date, Dimensions, Distinct, Email, File (covering ImageFile), IpAddress, Numeric, StringRule, Url, Uuid, Exists, Unique, AnyOf, Can, Enum, Password, and the mutable RequiredIf/ExcludeIf/ProhibitedIf rules. Classify container-resolved objects by ownership, not by property count or constructor arity. The internal Validator, closure/invokable wrappers and precomputed verifier are constructed explicitly on the supported execution paths; do not mark them merely because they hold execution state.
- Required constructor arguments do not prevent sharing: contextual primitive bindings can satisfy them while the outer unbound class is auto-singletoned. Cover both FormRequest method-injected Numeric and a required-argument rule resolved through contextual binding.
- Keep factories, stateless verifiers, worker-owned metadata caches and other legitimately shared services shared. Do not mark every validation class Transient or add service bindings unnecessarily.
- Clear accumulated messages as well as the failed flag at the start of `ClosureValidationRule::passes()` and `InvokableValidationRule::passes()`. Reusing a validator for a second input must not retain the first input's messages. Add a brief adjacent explanation that the same rule can validate new input on a later run, so a future upstream sync preserves the reset. Keep this a direct reset; do not add deferred-message lifecycle machinery.
- Preserve conditional callback evaluation timing outside the explicitly approved database capture change. Do not cache stateful Email/File/Password execution merely because their configuration can be displayed as a string.

## Consumers, documentation and cleanup

- Search all framework source/tests and the owner-designated application and private-package consumers for references, wrappers and composition of changed APIs. Follow the root consumer-check instructions; update private consumers directly on monorepo `main`, with their own review and commits. Do not expose private paths in public documentation.
- Verify tenancy-style presence wrappers through their existing fluent delegation. The examined wrapper clones Exists/Unique and adds a tenant condition; it does not stringify or consume the replaced verifier APIs, so no wrapper edit is needed for the new representation. Keep its one-query homogeneous wildcard coverage and tenant isolation assertions; change consumers only where the implementation exposes an actual incompatibility.
- Update Foundation's verifier alias, Factory/Validator integration, Data comparison/normalization and the benchmark command where internal compiler/cache signatures are consumed. Do not confuse an internal API update with authorization to run benchmarks.
- Put user guidance in `src/docs/validation.md`: typed conditions for custom verifiers/rule subclasses, canonical override methods, run-start capture and explicit runtime callbacks. Use short examples of the actual new API. Update Data guidance at its existing canonical page where the attribute output matters.
- In custom-rule documentation, explain that stateful application rules, including DataAwareRule/ValidatorAwareRule implementations, must be created with `new` or marked `Transient` when container-injected. Otherwise concurrent requests can share their data or validator reference.
- Add concise validation/Data README differences and a short `src/docs/porting-from-laravel.md` entry linking to the canonical documentation. Describe only developer-facing adaptation; do not list precision fixes, implementation details or performance work as porting differences.
- Remove obsolete connection warnings/fields, encoded-extra decoders, generated-condition closures, duplicate scalar normalization, unused helpers/imports, stale type aliases/docblocks and outdated tests of removed contracts. Preserve generic string-rule parsing, legitimate callbacks and applicable upstream behavior tests.
- Regenerate affected facade documentation only if an exposed signature changes. Do not change instruction files without separate owner approval.

### PHPStan assessment after implementation signoff

After the implementation, commits and owner benchmarking checkpoint above, assess `hypervel/phpstan` against the finished code on the same branch. Native signatures and final methods already diagnose unported verifier/rule overrides; do not duplicate those checks with custom rules.

Check existing validation analyzer assumptions and inference fixtures for the new builder types. For the worker-state defects, investigate whether method-injected mutable builders, application DataAwareRule/ValidatorAwareRule implementations, and calls that mutate a shared verifier expose a reliable gap in `WorkerStateRule` and its collectors. Compare realistic positive cases with legitimate worker-owned caches and setters. Propose an extension only if detection is reliable and complexity proportionate; obtain peer consensus and owner approval before adding a rule. Otherwise record the concrete limitation and add no speculative machinery. Fix analyzer defects rather than rewriting correct validation code to satisfy them.

## Verification and review

Run checks sequentially, from the worktree root. After each changed test file, run it immediately. Reuse unaffected passing results; do not run the entire CI matrix locally. Use task-owned isolated services for integration checks.

| Area | Required behavior and natural coverage |
|---|---|
| Native boundary | Test that valid subclasses use the new header, condition and export hooks. Native signatures/final methods enforce invalid legacy overrides; do not add subprocess tests merely to retest PHP's declaration checks. |
| Database declarations | Extend Exists/Unique/parser/verifier tests for six operators, repeated columns, literal sentinel-like strings, enums, ignored model IDs, inferred columns and overridden empty conditions/callbacks. String-rule decoding and fluent typed conditions must reach equivalent queries where their inputs mean the same thing. |
| Cache and preparation | Extend `ValidationRuleCompilerTest` and `ValidationRulePlanCacheTest`: getter/export call counts, hits/misses, subclass declarations, header differences, null tiers, no premature Stringable casts, reference detachment in both cacheable and callback-bearing plans, and no retained builder via WeakReference. Use two wildcard items sharing one builder to assert one export per run in base and custom Validators, and one database-declaration capture in the base path. Mutate the builder and validate again to prove one fresh capture and the updated result. Check reference detachment and original-object hooks in the custom path too. Test Data presence inference without exporting membership parameters. These are deterministic work-count assertions, not timing tests; use tiny inputs and no sleeps or benchmark harness. Retain LRU/cleanup coverage. |
| Batching | Extend existing `ValidationBatchDatabaseCheckerTestCase` shared by SQLite/PostgreSQL/MySQL/MariaDB, plus unit verifier tests. Cover JSON boolean equality/inequality extras, literal/repeated/membership conditions, cache reuse, one-query all-hit groups, misses/fallbacks, exclusions, duplicate candidates, collation and chunk boundaries. Keep current ordinary binding-equivalence tests; include integral floats against string columns and high-precision floats against DECIMAL columns as both extra predicates and candidates. |
| Connection isolation | A nested validation triggered from a rule override must not change the outer query's connection. Assert actual selected connections/results. Removing shared invocation state fixes the same coroutine race; do not duplicate this regression with a scheduling-only test. |
| JSON candidates | Cover scalar and array bool candidates, strings/numbers, JSON null/missing and SQL null on supported drivers. Reuse MariaDB connection coverage for equality/inequality/null-safe equality; preserve SQLite's documented query behavior. |
| Membership/Data | Extend existing In/NotIn/ArrayKeys/Contains/DoesntContain and Data normalizer/factory/attribute/accumulator tests. Include all five builders in normalizer coverage, preserving original builder identity and overrides. Cover precision-sensitive float membership, delimiter/quote/backslash values, null/false/integer distinctions, ExternalReference resolution, direct factory conversion and callback identity. |
| Numeric precision | Extend Numeric, compiler/executor and validator tests with boundary values, native float operands, numeric strings, exponent limits and non-finite inputs; verify compiled/delegated parity while retaining integer fast-path assertions where meaningful. |
| Lifetimes/messages | Cover the two realistic container regressions: method-injected Numeric in a FormRequest and a required-argument rule resolved through contextual binding. Do not repeat a fresh-instance assertion for every marker declaration. Use one shared repeated-run behavior dataset for closure/invokable messages, asserting the second run's actual errors. |
| Transferred work | Preserve email, missing-sentinel, not_regex and decimal regression tests; run relevant Testbench contracts after the shared sentinel move. |

Existing prototypes established typed-condition correctness on all four supported database drivers, native failure boundaries, subclass counterexamples, opaque-parameter ordering and typed membership/null-cache behavior. They are investigation evidence, not production implementations: remove their exact-class restrictions, redundant snapshots, old connection state and duplicated normalization rather than copying them wholesale.

Use query/export counts and cache identity to verify avoidable work without timing noise. Before performance benchmarks, obtain fresh confirmation that the machine is idle. Compare warm/cold string rules, typed database conditions, large homogeneous wildcard input and Data collections; inspect memory retention and SQL query counts as well as latency. Any meaningful remaining regression returns to the owner with affected cases and alternatives before acceptance.

At each coherent implementation boundary, run outstanding targeted tests and `composer analyse`; run `composer lint:fix` before scheduled code review/commit. Run `composer test:testbench` for the Testbench consumer changes and targeted PHPStan tests if that package changes. Leave full suites/matrices to CI unless a concrete unresolved failure requires them.

Fresh self-review must trace declarations through parsing, capture, key construction, execution and Data consumers, then verify all documentation against the resulting source. Peer code review covers the whole final change and all findings, independently of prior design/plan signoff. Remove dead code and superseded wording before declaring completion.
