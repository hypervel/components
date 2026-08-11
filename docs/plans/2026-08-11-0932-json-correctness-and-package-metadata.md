# JSON correctness and package metadata plan

## Status and objective

Correct Hypervel's JSON nesting and failure contracts across framework storage, transport, validation, diagnostics, testing, and package discovery. Values accepted for storage must remain readable; invalid JSON must not become `null`, `false`, an empty collection, missing discovery metadata, an unredacted raw body, or an unrelated downstream type error.

This is one normal-sized Components change, not a staged rollout. Implementation is complete; verification and review are current. Re-read this file with root `CLAUDE.md` and `AGENTS.md` after every context compaction.

The final design must:

- share one generic nesting contract through `Support\Json` without replacing Eloquent's distinct Laravel-style codec;
- preserve contextual Eloquent write errors, custom codecs, and the stored-empty-string convention;
- fail before executing queries, encrypting values, publishing package manifests, or rendering invalid console output;
- prevent Telescope's supported structured request paths from retaining configured secrets when parsing fails;
- keep intentional non-throwing boundaries, including malformed maintenance cookies, unchanged;
- add no locks, caches, container resolution, I/O, worker state, or extra successful-path JSON traversal outside the exception-chunk transaction required to keep Telescope's visibility update and replacement insert atomic.

## Core anti-overengineering rules

The following wording is retained verbatim from the core audit plan. Its principle numbering is also retained; principles 1–6 remain in that plan. In principle 9, “later in this plan” refers to the core audit plan's established remediation vocabulary.

### What this audit is not

This audit is not permission to add defensive machinery for every imaginable failure. Do not add an abstraction, state machine, retry loop, configurable timeout, registry, mutex, context slot, cache, or compatibility API merely because it sounds robust.

Complexity must pay for itself with at least one of:

- a demonstrated failure;
- a complete source trace proving a realistic vulnerable schedule;
- a clear general capability with real consumers and owner approval;
- deletion of greater or riskier complexity elsewhere.

Typical Laravel lifecycle semantics define the supported contract. A package that intentionally relies on model events, middleware, listeners, transactions, or another documented mechanism is not defective merely because userland can explicitly bypass that mechanism. Do not build a parallel enforcement path for `withoutEvents()`, raw database writes, disabled middleware, direct transport access, or comparable deliberate bypasses unless the public contract explicitly promises behavior through that bypass.

Underengineering is equally a failure. Fix every verified defect completely at its lowest owning boundary, never with a partial fix or a local patch over a broken shared contract, and always surface meaningful evidence-backed improvements rather than dropping them to avoid effort. Restraint applies to speculative machinery and cosmetic change, not to complete fixes or worthwhile opportunities.

Do not treat an upstream difference as a bug without tracing it. Do not treat upstream parity as proof of correctness. A real Hypervel defect remains a defect when Laravel, Hyperf, Symfony, or an SDK has the same hole.

The audit categories are discovery lenses, not boundaries around what may be corrected. Any genuine issue discovered while auditing, implementing, testing, or reviewing must be investigated, assigned to its lowest owning boundary, and taken through the applicable consensus, implementation, validation, review, and approval workflow—even when it is outside the current package, initial taxonomy, or changed diff. Do not dismiss a verified issue as unrelated or defer it merely to preserve package order. This rule applies only after the evidence threshold is met; it does not turn speculative concerns, deliberate bypasses, unsupported use, or contract violations into work.

### 7. Preserve hot-path quality

For every fix, inspect:

- additional allocations;
- container or facade resolutions;
- locking and atomics;
- hashing and serialization;
- new yields or sleeps;
- retries and polling;
- logging or exception construction;
- retained worker memory;
- cache invalidation and eviction.

A correctness guard on a cold failure path has a different cost from a new lock or resolver on every request. State the difference explicitly.

Any proposed change with a measured or source-proven hot-path regression requires explicit owner approval before implementation, even when it fixes a defect. Present the expected frequency and magnitude, the evidence, and the viable alternatives. Do not hide an unavoidable tradeoff inside a general correctness claim.

Performance improvements must provide a meaningful practical benefit after accounting for code complexity and divergence from upstream. Measure representative behavior where practical. Always surface an evidence-backed opportunity to the owner, but do not implement it without approval; a micro-optimization within measurement noise is neither a reason to diverge nor an actionable finding.

### 8. Remove superseded design completely

When a fix changes the owning model, delete obsolete helpers, callbacks, properties, config keys, comments, tests, and documentation. Do not leave a compatibility path or comment describing behavior that no longer exists. Preserve intentional upstream comments unless the new design makes them incorrect.

### 9. Treat remediation patterns as candidates

The established patterns later in this plan are a vocabulary, not a lookup table. Choose among per-call parameters, immutable values, scoped bindings, cloning, CoroutineContext, factories, explicit ownership, static reset, or resource teardown only after proving the real lifetime and owner.

### 10. Reject speculative complexity

Record low-confidence concerns under rejected or unresolved analysis. Do not implement them. Surface every evidence-backed, meaningful non-defect improvement to the owner with its benefit, cost, and alternatives, then stop for explicit approval. This requirement exists to keep worthwhile opportunities visible, not to discourage finding them.

## Verified behavior and contracts

### Native depth asymmetry

PHP 8.4 accepts an array nested through 512 containers with `json_encode(..., depth: 512)`, but the resulting bytes require `json_decode(..., depth: 513)` and `json_validate(..., depth: 513)`. A 513-container value fails encoding at 512. The public Hypervel unit will therefore be **maximum nested containers**, translated only at native read/validation boundaries.

Native facts that remain authoritative:

- encode returns `false` unless `JSON_THROW_ON_ERROR` is set;
- decode returns `null` for both valid JSON `null` and failure unless throwing is enabled;
- validate returns `false`, accepts only `JSON_INVALID_UTF8_IGNORE`, and raises `ValueError` for invalid depth/flags;
- validate should replace decode-only validation because it avoids building an unused value;
- a `Jsonable` receives flags through `toJson(int $options)`, but that interface cannot receive a depth.

### Separate JSON owners

`Hypervel\Support\Json` is Hypervel-owned generic infrastructure introduced when `hyperf/codec` was removed. Foundation and Validation already depend on Support. It owns generic encode/decode/validate depth units and throwing behavior.

`Hypervel\Database\Eloquent\Casts\Json` remains independent. It owns custom encoder/decoder callbacks, worker-lifetime reset, Laravel's non-throwing encoder result, and Eloquent's `'' => null` storage convention. Its callers preserve contextual model errors. Routing it through Support would erase those contracts and add the wrong behavior.

### Telescope JSON ownership

Telescope has three distinct JSON boundaries:

- database entries and diagnostic object normalization are framework-produced storage round trips and fail loudly before corrupting a row;
- watcher request/response bodies are external observations and retain non-throwing fallbacks, but declared structured request media types must never fall through to unredacted raw storage;
- Telescope's private response parsers may accept the framework ceiling without changing the public `Http\Client\Response` decoding contract.

The client watcher's normal `asJson()`, `asForm()`, and `asMultipart()` requests carry `hypervel_data` and use the structured masking path. Its raw path is still reachable through supported `withBody()`, an explicit Guzzle `body`, and third-party PSR-7 traffic. Redaction covers declared JSON, declared URL-encoded form data, and headerless bodies that parse or look like JSON objects/arrays. Other undeclared, opaque, and explicit plain-text bodies have no supported field model and retain their existing raw representation; do not guess that a headerless `k=v` string is form data.

### Fail-loud package metadata

Missing `composer.json` or `vendor/composer/installed.json` is a supported partial-repository/no-vendor state and remains empty. Existing code instead also treats corrupt syntax, invalid containers, nameless entries, non-string versions, and invalid `extra.hypervel` as absent. That can publish an empty package cache and omit providers, aliases, versions, and PHPUnit test-state cleanup.

Root `extra.hypervel.dont-discover` is the application-controlled recovery surface. A root `*` skips parsing entirely. A specifically ignored package is identified and skipped before validating metadata the application chose not to consume. Package-owned `dont-discover` remains a discovery value, not a circular error-suppression system.

## Implementation

### 1. Generic Support JSON contract

Update `src/support/src/Json.php`:

- Add `public const int MAXIMUM_NESTING_DEPTH = 512`.
- Preserve parameter order and named arguments:

```php
encode(mixed $data, int $flags = JSON_UNESCAPED_UNICODE, int $depth = self::MAXIMUM_NESTING_DEPTH): string
decode(string $json, bool $assoc = true, int $depth = self::MAXIMUM_NESTING_DEPTH, int $flags = 0): mixed
validate(string $json, int $depth = self::MAXIMUM_NESTING_DEPTH, int $flags = 0): bool
```

- `encode()` passes `$depth` to native encode and continues forcing `JSON_THROW_ON_ERROR`.
- For `Jsonable`, call `toJson($flags | JSON_THROW_ON_ERROR)`. This fixes discarded caller/default flags while retaining object-owned serialization. State in the docblock that `Jsonable` owns nesting because its contract cannot accept depth; do not reparse its output.
- `decode()` and `validate()` translate a positive public depth below `PHP_INT_MAX` to native `$depth + 1`. Pass non-positive and `PHP_INT_MAX` values through so native PHP raises `ValueError` instead of making zero valid or overflowing the helper arithmetic.
- `decode()` keeps `JSON_THROW_ON_ERROR` and passes supported caller flags.
- `validate()` passes flags unchanged. Do not copy the sibling THROW pattern: native validation rejects `JSON_THROW_ON_ERROR`.
- Use one private `nativeDecodingDepth()` helper for the two real callers and the non-obvious unit rule. Do not add a codec interface, service, container binding, facade, cache, or configurable ceiling.

Update `Str::isJson()` to keep its non-string guard and delegate to `Support\Json::validate()`. `Stringable::isJson()` already delegates to `Str`; do not duplicate the call or depth rule there. The current explicit native depth 512 rejects documents produced at Support's supported maximum.

Use the same contract in Hypervel's response-test readers:

- `AssertableJsonString` decodes string and `Jsonable` input through Support, catches only `JsonException`, and retains its existing `null` sentinel for invalid JSON. Keep `JsonSerializable` and array input unchanged.
- `TestResponse::ddBody()` uses `Support\Json::validate()` for JSON detection.
- `TestResponse::dump()` decodes through Support and falls back to the original bytes only on `JsonException`. Pass `assoc: false` and add a concise WHY comment so debugging output remains object-shaped like Laravel's instead of silently changing to arrays.

Correct the same native-depth unit mismatch at the two Filesystem-owned JSON readers:

- `Filesystem::json()` and `FilesystemAdapter::json()` pass `Json::MAXIMUM_NESTING_DEPTH + 1` to native decode.
- Preserve their existing flags-controlled contract exactly: malformed JSON returns null by default, callers may opt into `JSON_THROW_ON_ERROR`, and a missing adapter file remains null.
- Do not route these methods through throwing `Support\Json`, add a second depth constant, or add a helper for the one derived expression.

Route `Support\Composer::hasPackage()` and both sides of `modify()` through `Support\Json`:

- Both existing decodes are associative, so `Json::decode()` preserves their shape while translating the public 512-container limit to native 513.
- `modify()` calls `Json::encode()` with only `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`; Support already adds THROW. Its callback contract returns an array, so neither `Jsonable` nor `Arrayable` can bypass those formatting flags.
- Assign the callback result and encoded bytes to local variables before looking up the file mode or calling `replace()`. This closes a direct read-after-write defect and makes failure ordering explicit: the current class can encode 512 containers and then reject the same file on its next decode, while a 513-container callback result throws before mode lookup or replacement and leaves the original bytes unchanged.
- Do not add a Composer-specific depth ceiling or root-shape validator. Composer metadata is schema-shallow, the external binary's internal ceiling is not Hypervel's contract, and `hasPackage()` has no production caller whose valid-scalar false result causes meaningful harm.

Correct the remaining framework-owned self-round-trips:

- `FileBasedMaintenanceMode::activate()` and `data()` use Support encode/decode, preserving pretty-print, associative output, malformed JSON exceptions, and the separate non-array payload exception.
- JSON `Session\Store` keeps its throwing native writer and intentional malformed-data-to-empty-session reader. Only pass `Json::MAXIMUM_NESTING_DEPTH + 1` to native decode so every successful 512-container write remains readable.
- `Http\Client\Request::json()` uses Support decode for the framework-produced outgoing request body, retaining its associative array check and cached result.
- `Http\JsonResponse::getData()` changes only its default native depth from 512 to `Json::MAXIMUM_NESTING_DEPTH + 1`. Explicit caller depths remain native. This prevents `setEncodingOptions()` from decoding a 512-container response as null and silently replacing its body with JSON `null`.
- `Support\Xml::toArray()` uses Support encode/decode for its SimpleXML-to-array round trip.
- `Inertia\Testing\AssertableInertia::fromTestResponse()` uses Support encode/decode for arbitrary page props.

Correct the Collections-owned self-round-trips without creating a dependency cycle:

- `EnumeratesValues::fromJson()` changes only its default native depth from 512 to 513; explicit depths remain native.
- `EnumeratesValues::jsonSerialize()` and `Arr::from()` pass native depth 513 when decoding `Jsonable::toJson()` output.
- Add the same concise comment at all three native decode sites: Support depends on Collections, so the depth cannot reference `Support\Json`; the cross-package behavioral tests keep the 512-container contracts aligned.
- Do not add a public `Arr` constant, constants-only interface, internal class, or duplicated trait/private constants. JSON depth is not an Arr-owned API, PHP has no package-private shared location, and three explained native literals are smaller and clearer.

Move serialized-closure response ownership to Concurrency and remove its duplicated readers:

- Move `InvokeSerializedClosureCommand` from Foundation to `Hypervel\Concurrency\Console`, matching current Laravel ownership while keeping registration in `FoundationServiceProvider`.
- Add internal `Hypervel\Concurrency\SerializedClosureResult::decode(string $output): mixed`. It owns gzip-marker truncation, Support decode, envelope validation, remote-exception reconstruction, strict base64 decoding, and guarded unserialization. Its docblock states that it returns the unserialized result or throws the reconstructed remote `Throwable`.
- Have `ProcessDriver::run()` and Testbench's `Foundation\Process\ProcessResult::output()` delegate to it after their caller-owned process/raw-command checks. Use domain errors—`Invalid serialized closure response envelope.` and `Unable to decode the serialized closure result.`—instead of parameterizing caller nouns.
- Keep the command's explicit native calls, transport flags, and native-512 parameter stability precheck together. The parent reads the complete envelope at Support's public 512-container limit, translated to native 513; the parameters subtree is one container shallower, so its native-512 precheck is deliberate. Routing only the outer writer through Support would add no behavior and would obscure the transport-specific `JSON_INVALID_UTF8_SUBSTITUTE` / `JSON_PRESERVE_ZERO_FRACTION` contract and subtree depth math.
- Retain the command's caught `report()` call as Laravel's accepted Foundation soft dependency. An undefined helper is already contained by the `Throwable` catch; Concurrency must not declare Foundation or add reporting machinery that creates a package cycle.
- Add direct `symfony/console:^8.1` to `src/concurrency/composer.json` and `hypervel/concurrency:^0.4` to `src/testbench/composer.json`.
- Move the command test and shared exception fixture from Foundation to Concurrency, update all three fixture consumers, and centralize exhaustive envelope tests in `SerializedClosureResultTest`. Delete duplicated decoder-contract matrices from the two callers while retaining their own process, mapping, environment, raw-command, and delegation behavior.
- Move `tests/Integration/Concurrency/ConcurrencyTest.php` to `tests/Concurrency/ConcurrencyTest.php`; it uses fake processes and no external service.

Do not generalize this into a framework-wide native-decode rewrite. Protocol, external-request/response, developer-authored file, and upstream API readers own different limits and failure contracts. In particular, do not change `Http\Request::json()`, `Http\Client\Response`, or `Translation\FileLoader`: they consume bytes another owner produced, and the validated request-cast path carries deep JSON inside a shallow string field. The audited framework-produced queue payload is also not exposed to this asymmetry: the job object graph is a serialized string within a shallow JSON envelope, and payload creation already throws `InvalidPayloadException` after encoding failure.

### 2. Foundation request casts and Validation

Update `src/foundation/src/Http/Traits/HasCasts.php`:

- Type `fromJson()` as `mixed` and delegate to `Support\Json::decode($value, ! $asObject)`.
- Do not special-case empty strings. Normal validated requests reject empty JSON; raw/unvalidated casting must surface invalid input rather than turn it into empty data.
- Remove the unused protected `asJson()` method. It was copied into Hypervel's request-casting feature, has no source/test caller, is undocumented, and does not participate in casting. Backwards compatibility with unreleased Hypervel-only code is not a reason to retain dead code.

Update both JSON rule paths:

- `src/validation/src/Concerns/ValidatesAttributes.php::validateJson()`
- `src/validation/src/PlanExecutor.php::executeInlineJson()`

Retain each path's rule-specific scalar/stringable prechecks, then call `Support\Json::validate()`. Remove the dead PHP 8.4 `function_exists('json_validate')` and decode fallback. Keep the compiled executor inline rather than creating a shared validator object or adding dispatch overhead.

Correct `src/docs/validation.md` so values cast as `array`, `collection`, `json`, or `object` are demonstrated as JSON strings validated by `json`, rather than a PHP array validated by `array`. Keep the prose Laravel-style and do not introduce PHP-array pass-through behavior.

Correct the corresponding `tests/Foundation/Http/CustomCastingTest.php` fixture/rules to exercise the validated JSON-string path instead of bypassing validation to hide the mismatch.

### 3. Eloquent JSON codec and writers

Update `src/database/src/Eloquent/Casts/Json.php`:

- Add private typed `MAXIMUM_NESTING_DEPTH = 512` beside the codec's static callbacks.
- Run custom callbacks first and unchanged.
- Default encode calls native encode at depth 512 and deliberately returns `string|false`, without `JSON_THROW_ON_ERROR`; the public method remains `mixed` because custom encoders are unconstrained. Add a short WHY comment: Eloquent writers convert `false` to `JsonEncodingException::forAttribute()` with model/key context.
- Default decode preserves `'' => null`; comment that this is Eloquent's established empty stored representation, not malformed JSON recovery.
- Default non-empty decode uses native depth 513 with `JSON_THROW_ON_ERROR`. Valid JSON `null` still returns null; malformed/deep JSON raises `JsonException`.

Update all eight first-party JSON class-cast writers:

- `AsArrayObject`, `AsCollection`, `AsEncryptedArrayObject`, `AsEncryptedCollection`, `AsEnumArrayObject`, `AsEnumCollection`, `AsFluent`, and `AsDataObject`.
- In the seven anonymous casters, type the contract's `$model` parameter as `Model`.
- Check the encoded result before returning it or encrypting it. Throw `JsonEncodingException::forAttribute($model, $key, json_last_error_msg())` on exact `false`.
- Keep the same exact-`false` signal and diagnostic owner as primitive casts. A custom encoder that delegates to native JSON retains its native error; an encoder that returns `false` without performing JSON encoding violates the callback's string-producing contract and does not justify callback-result metadata or worker-global error tracking.
- Keep the guard local at each writer; a new public helper or callback abstraction would make a simple failure path harder to follow.
- `AsDataObject` uses the Eloquent codec for both directions, accepts decoded arrays including `[]`, returns null for other successfully decoded shapes, and returns the normal `[$key => $encoded]` set response.

Representative writer shape:

```php
$encoded = Json::encode($value);

if ($encoded === false) {
    throw JsonEncodingException::forAttribute($model, $key, json_last_error_msg());
}

return [$key => $encoded];
```

Make reader output types explicit after either default or custom decode:

- unencrypted `AsArrayObject`, `AsCollection`, `AsEnumArrayObject`, and `AsEnumCollection` retain their array guards;
- encrypted ArrayObject/Collection casts add the same array guard before construction;
- `AsDataObject` requires an array;
- `AsFluent` accepts arrays or objects because `Fluent` supports both. Preserve object-returning custom decoders and add a concise WHY comment.

Update `HasAttributes::fillJsonAttribute()` to call existing `castAttributeAsJson()`. This removes duplicate flag/error handling and prevents storing or encrypting `false`.

### 4. Eloquent repair semantics

`HasAttributes::originalIsEquivalent()` currently decodes/casts current and original values together. A corrupt original therefore makes a valid assignment impossible to save through Eloquent. Change the four JSON comparison branches:

1. object/collection primitives;
2. all primitive casts, including encrypted JSON variants;
3. `AsArrayObject` / `AsCollection`;
4. `AsEnumArrayObject` / `AsEnumCollection`.

For each branch, evaluate the current assigned value outside the catch. Evaluate only the original value inside `catch (JsonException)`, returning `false` when the original is readable/decryptable but invalid JSON. This marks a valid replacement dirty and permits repair.

```php
$current = /* decode or cast the assigned value */;

try {
    $original = /* decode or cast the original value */;
} catch (JsonException) {
    return false;
}

return $current === $original;
```

Do not catch `DecryptException`. A wrong key or corrupt ciphertext may still contain recoverable data under the correct key and must fail without authorizing overwrite. Encrypted class-cast comparison remains unchanged because it compares decrypted serialized strings rather than decoded JSON.

### 5. Query and console native encodes

Add `JSON_THROW_ON_ERROR` at every remaining Database native encode whose result is consumed as a string/binding:

- `Query\Grammars\Grammar::prepareBindingForJsonContains()` retains `JSON_UNESCAPED_UNICODE`;
- `MySqlGrammar::prepareBindingsForUpdate()` (also inherited by MariaDB);
- `PostgresGrammar::prepareBindingsForUpdateFrom()`;
- `PostgresGrammar::prepareBindingsForUpdate()`;
- `SQLiteGrammar::prepareBindingsForUpdate()`;
- `Database\Console\TableCommand::displayJson()`;
- `Database\Console\ShowCommand::displayJson()`.

This keeps errors at the encoding call rather than passing `false` to a query or Symfony output. Native exceptions remain unwrapped. While touching `PostgresGrammar`, replace the adjacent `trim($baseWheres) == ''` with the required strict string comparison; its operand is already a string and behavior remains unchanged.

Do not replace direct native grammar/console calls with Support or Eloquent codecs. These sites need only native failure signaling and have neither generic public depth semantics nor model context.

### 6. Telescope storage and redaction

Update `src/telescope/src/Storage/DatabaseEntriesRepository.php` so every stored entry uses the same readable codec:

- Add one focused `encodeContent(array $content): string` helper used by ordinary rows, exception rows after adding `occurrences`, and updates after merging changes. It first encodes the complete content through `Support\Json` with `JSON_INVALID_UTF8_SUBSTITUTE`.
- On `JSON_ERROR_DEPTH` only, encode each top-level field as `[$key => $value]` with the same flags and ceiling, replace every depth-failing value with the existing scalar `Purged By Telescope`, then encode the corrected complete content. A one-key wrapper has the same root reservation as the field in the full content array, so siblings do not affect the result and at least one field must be replaced. Rethrow every non-depth error from either pass; this includes a later INF/NAN, recursion, or unsupported-type error hidden behind an earlier depth failure.
- This failure-only recovery preserves each watcher's required shallow schema and all other useful fields. Do not replace the complete content with a sentinel: event and request screens structurally consume shallow fields such as `listeners` and `middleware`. Do not add a recursive sanitizer, watcher/type map, storage ceiling override, or successful-path preflight.
- `update()` decodes the retained content through Support before merging changes, then uses `encodeContent()`. Let `array_merge()` fail naturally if a corrupt stored value is not an array; do not replace it with an empty value. A depth-heavy changed top-level field is purged and later updates continue, while malformed retained JSON and non-depth encoding defects remain fail-loud.
- For each exception chunk, group by family hash and `sortKeys()` before querying occurrence counts and collecting last UUIDs. Build and encode the complete insert-row array before any write. The deterministic family order is required because the transaction below retains locks across family updates; first-appearance order would let two concurrent multi-family chunks acquire the same locks in opposite orders and deadlock.
- On `DB::connection($this->connection)`, use `transaction(Closure)` with its default single attempt around only the existing sorted per-family visibility clears and the already-built chunk insert. Encoding stays outside the transaction. This makes clear-then-insert atomic: encoding, update, or insert failure leaves the prior visible row intact. Keep update-before-insert because insert-first writers can clear each other's newly inserted visible rows. The transaction preserves the existing possibility of duplicate visible rows during concurrent first writes; eliminating that cosmetic, self-repairing race would require unsupported cross-engine schema or family-lock machinery.
- Keep the existing chunked and partial-batch model. Depth recovery changes only offending top-level field values and retains the row UUID, type, family state, and tags, so it requires no filtering or tag bookkeeping. A non-depth failure in a later exception chunk leaves prior chunks stored but prevents tags for every exception chunk and prevents every ordinary entry and its tags from being stored; `Telescope::executeStore()` reports the failure, skips later updates/hooks, and flushes the in-memory queues. Do not add a whole-batch transaction, whole-batch pre-encoding, duplicate validation, row filtering, or recovery for non-depth defects: they would widen locks, remove the memory bound, serialize twice, or hide an instrumentation defect.
- The exception transaction adds one begin/commit pair per nonempty exception chunk and holds matched family-row locks plus its pooled connection through the update/insert pair. This can serialize same-family writers during an exception storm, but batches without exceptions pay nothing. If the configured connection already has an outer transaction, the normal savepoint behavior applies and Telescope retains its existing participation in the application's eventual commit or rollback.

Correct Telescope's diagnostic object normalization:

```php
Json::decode(json_encode($value, JSON_THROW_ON_ERROR))
```

- Apply this shape to the native normalization expressions in `ExtractProperties::from()`, `EventWatcher::extractPayload()`, and `RequestWatcher::extractDataFromView()`.
- Keep native encode because Telescope is inspecting the object's actual shape. `Support\Json::encode()` would instead invoke `Jsonable` or `Arrayable` and record the representation the object chooses to publish.
- Add that WHY once before each method's normalization group—three comments cover all expressions without repetition.
- Use Support decode in `ModelWatcher::recordHydrations()` when the framework-produced entry has already become a stored JSON string. This aligns a fixed shallow shape with its storage codec; it is not presented as a reachable depth defect.

Refactor `ClientRequestWatcher` around one protected structured-payload formatter used by request and response paths. It accepts the array, hidden-field list, and byte limit; masks first, encodes once with `JSON_INVALID_UTF8_SUBSTITUTE` at `Json::MAXIMUM_NESTING_DEPTH - 1`, purges on exact `false`, and applies the existing truncate-or-purge option to those exact masked bytes. One container is reserved for the entry-content root; do not add a Telescope depth constant. The request-facing `payload()` delegates to it with request settings. The normal path remains two traversals (mask and encode), and oversized truncation drops from three to two. The default oversized purge path rises from one traversal to two and allocates the masked encoded bytes because the limit must describe retainable data rather than pre-redaction secrets. The existing raw-JSON path already masks before measuring; this unifies the structured path with that correct order and closes its current encode-failure fallthrough.

Correct the raw request boundary without turning arbitrary bodies into a new parsing framework:

- Decode JSON once, associatively and non-throwing. Derive `$maximumContainers = Json::MAXIMUM_NESTING_DEPTH - 1` for the value beneath the entry-content root and pass native depth `$maximumContainers + 1`. An array goes through the request structured formatter; valid scalar JSON keeps its raw representation because it has no addressable fields.
- If decoding fails, return `Purged By Telescope` when the normalized content type contains `/json` or `+json`, or the first non-JSON-whitespace byte is `{` / `[`. Find that byte with `strspn($content, " \t\n\r")` and an offset lookup; do not allocate a full `ltrim()` copy of a potentially large body. The raised native depth is a security guard against entering the raw branch, not a change to the public HTTP response decoder's external-input contract.
- For `application/x-www-form-urlencoded`, call native `parse_str()` and always pass its array through the request structured formatter. It has no failure/raw fallback, so supported form bodies cannot retain the default hidden `password` fields. Native bracket syntax composes with `Arr` dot notation; `parse_str()` converts literal dots in field names to underscores, while Telescope dot notation continues to mean nesting.
- Keep opaque/custom and explicit `text/plain` content raw under the existing byte limit. Do not add XML/custom parsers or purge bodies that have no supported field model.
- Keep stream rewind/restoration and the existing early purge for a known oversized non-truncated stream.

Update client and application response capture:

- `ClientRequestWatcher::getResponsePayload()` performs one non-throwing associative native decode at `$maximumContainers + 1`, sends arrays through the shared structured formatter with response settings, purges immediately on `JSON_ERROR_DEPTH`, and preserves redirect, plain-text, empty, and HTML fallbacks. The error code alone proves a deeply nested JSON value; do not add a media-type gate that would miss missing or incorrect response headers. This is a private diagnostic-parser correction, not a new contract for `Http\Client\Response::decodeBody()`.
- `RequestWatcher::response()` performs the same single decode and reuses its result, purging on `JSON_ERROR_DEPTH` before its remaining fallbacks. Its content is framework-produced, so this is a real `JsonResponse` read-after-write boundary as well as removal of a duplicate parse.
- Do not add RequestWatcher preflight encodes for request input, session attributes, facade context, or view data. These values can legitimately exceed the child ceiling, but `encodeContent()` purges only the offending top-level field with no successful-path traversal and covers the same lifted values in EventWatcher, JobWatcher, CacheWatcher, LogWatcher, and future watchers.
- Do not change the multipart `json_encode()` representability probe. Supported multipart contents reach the watcher as string/resource/stream/file values; unsupported array contents fail HTTP request construction before recording.

### 7. Package manifest discovery

Update `src/foundation/src/PackageManifest.php` without adding Composer runtime dependencies, schema DTOs, or a generic metadata parser.

Add two focused protected static helpers:

- a package-name helper used by installed-package discovery and Testbench root-package discovery, accepting already-array metadata plus its location, formatting the name through `formatPackageName()`, and requiring both the raw and formatted names to be non-empty strings;
- an `extra.hypervel` helper used by installed-package discovery, Testbench root-package discovery, and `rootHypervelExtra()`, accepting already-array metadata plus its location, treating absent or unaddressable parent `extra` as empty, and rejecting an explicitly present non-array `extra.hypervel`.

The split preserves installed discovery's required name → root-ignore → version/configuration order without duplicate validation, flags, callbacks, or parsing. Both helpers have multiple real callers and one diagnostic owner. Keep installed discovery static. `TestStateRegistrars` discovers and registers registrars during PHPUnit extension bootstrap, before any Testbench application exists; only the callbacks they install run after each test application is destroyed. An instance path would make Testing construct a partly usable Foundation `PackageManifest` with a null manifest path solely to reach a formatting seam already supplied across installed and root discovery by late-static-bound `formatPackageName()`. Do not add a parser class, DTO, public API, mixed-input helper, callback, or instance-discovery path.

`discoverInstalledPackages()`:

1. Return `[]` before reading `installed.json` when `$baseIgnore` contains `*`.
2. Keep a missing file as `[]`; otherwise decode through `Support\Json`.
3. Require an array root and, when present, an array Composer 2 `packages` member. Throw `UnexpectedValueException` naming the path for structural failures; native syntax/depth/UTF-8 failures remain `JsonException`.
4. For every entry, first require an array, then use the package-name helper. Give the entry-array failure the same diagnostic shape as Testbench's root-array failure. Compare the returned manifest key to root ignores. This matches existing manifest key semantics without adding full Composer-schema validation.
5. Skip a specifically root-ignored package before reading its version or `extra.hypervel`.
6. For consumed packages, allow a missing/null/string version and reject other types with the package/index in the error.
7. Read configuration through the `extra.hypervel` helper. Do not validate values inside it: providers, aliases, `dont-discover`, and `test-state` keep their existing consumer-owned Laravel-style casts/validation.
8. Collect package-owned `dont-discover`, apply the final ignore list, and return the same manifest shape as today.

An invalid/nameless entry remains fatal because a specific ignore cannot identify it and Composer always emits a package name. Do not add a second pass allowing dependency-owned metadata to suppress another dependency's corruption.

`rootHypervelExtra()`:

- Keep missing root `composer.json` and absent `extra.hypervel` as null.
- Decode through Support and require an array root, then use the shared `extra.hypervel` helper. Throw precise native/`UnexpectedValueException` failures rather than silently dropping root discovery/test-state metadata.
- Do not validate arbitrary values inside `extra.hypervel`.

Keep structural diagnostics stable and actionable without introducing exception classes or a parser abstraction. Each `UnexpectedValueException` names the metadata path and failing location: root/`packages`, entry index, formatted package name, `version`, or `extra.hypervel`. Syntax, UTF-8, and depth errors remain native `JsonException` messages.

`build()` already discovers before `write()`. Preserve this order so an explicit rebuild over a valid cache throws before the atomic cache replacement. The generated PHP cache path needs no new validation: it is written from validated arrays through `var_export()` and atomic `Filesystem::replace()`, invalid PHP naturally throws, and hand-editing a valid cache is a deliberate low-level bypass.

Update Testbench's `Foundation\PackageManifest` without weakening its typed boundary:

- `providersFromTestbench(): ?array` reads its `composer.json` through `Filesystem::json(..., JSON_THROW_ON_ERROR)`, checks the decoded root is an array before returning, and throws a precise metadata-path `UnexpectedValueException` for a valid non-array root. This check must remain here: widening the return type or parsing twice to move it into the shared helper would make the API worse.
- Narrow `providersFromTestbench()`'s docblock to `null|array<string, mixed>` so it promises only the decoded array root the method guarantees after that check.
- `providersFromRoot()` treats only null as absence, then obtains the formatted root key from the shared package-name helper and reads configuration through the shared `extra.hypervel` helper. Its existing root package remains merged after installed discovery.
- Retain protected `format()` unchanged as current Laravel 13.x protected parity surface even though no first-party caller remains. Existing Testbench manifest assertions already pin the formatted root key; do not add a synthetic override test.
- Keep `packagesToIgnore()` returning `[]`. Base installed discovery still applies package-owned ignores; Testbench's application/runtime ignores remain read-time behavior in `getManifest()`.
- Malformed installed metadata fails before the root merge and manifest write, so no partial cache is published. Testbench's runtime filtering no longer hides corrupt installed metadata; record this intentional fail-loud behavior in the PR/change summary.

The Testbench subprocess regression must use a scratch package root, call `Process::run()`, and assert nonzero status plus useful native failure text on stderr. Do not wrap the production exception merely to customize subprocess output.

## File map

| Owner | Source/docs | Tests |
|---|---|---|
| Generic JSON | `src/support/src/Json.php` | `tests/Support/JsonTest.php` |
| Collections round trips | `src/collections/src/Arr.php`, `src/collections/src/Traits/EnumeratesValues.php` | `tests/Support/{SupportArrTest,SupportCollectionTest}.php` |
| Composer file JSON | `src/support/src/Composer.php` | `tests/Support/ComposerFileTest.php` |
| Filesystem JSON | `src/filesystem/src/{Filesystem,FilesystemAdapter}.php` | `tests/Filesystem/{FilesystemTest,FilesystemAdapterTest}.php` |
| Framework-owned JSON round trips | `src/foundation/src/FileBasedMaintenanceMode.php`, `src/http/src/{JsonResponse,Client/Request}.php`, `src/session/src/Store.php`, `src/support/src/Xml.php`, `src/inertia/src/Testing/AssertableInertia.php` | `tests/Foundation/FoundationFileBasedMaintenanceModeTest.php`, `tests/Http/{HttpJsonResponseTest,HttpClientTest}.php`, `tests/Session/SessionStoreTest.php`, `tests/Support/XmlTest.php`, `tests/Inertia/Testing/AssertableInertiaTest.php` |
| Serialized-closure transport | move `src/foundation/src/Console/InvokeSerializedClosureCommand.php` to `src/concurrency/src/Console/`; add `src/concurrency/src/SerializedClosureResult.php`; update `src/concurrency/src/ProcessDriver.php`, `src/testbench/src/Foundation/Process/ProcessResult.php`, provider registration, and Concurrency/Testbench manifests | move command test and fixture to `tests/Concurrency/`; add `SerializedClosureResultTest.php`; move/update `ConcurrencyTest.php`; narrow `tests/Testbench/Foundation/Process/ProcessResultTest.php` |
| JSON predicates/test readers | `src/support/src/Str.php`, `src/testing/src/{AssertableJsonString,TestResponse}.php` | `tests/Support/{SupportStrTest,SupportStringableTest}.php`, `tests/Testing/TestResponseTest.php` |
| Request casts | `src/foundation/src/Http/Traits/HasCasts.php`, `src/docs/validation.md` | `tests/Foundation/Http/CustomCastingTest.php` |
| Validation | `src/validation/src/Concerns/ValidatesAttributes.php`, `src/validation/src/PlanExecutor.php` | `tests/Validation/ValidationValidatorTest.php`, `tests/Validation/ValidationPlanExecutorTest.php`; compiler classification remains in `ValidationRuleCompilerTest.php` |
| Eloquent codec/writers | `src/database/src/Eloquent/Casts/{Json,AsArrayObject,AsCollection,AsEncryptedArrayObject,AsEncryptedCollection,AsEnumArrayObject,AsEnumCollection,AsFluent,AsDataObject}.php` | new Testbench-based `tests/Database/DatabaseEloquentJsonCastTest.php`; reset coverage in `DatabaseEloquentModelTest.php` |
| Eloquent assignment/repair | `src/database/src/Eloquent/Concerns/HasAttributes.php` | `tests/Integration/Database/EloquentModelJsonCastingTest.php`, encrypted casting/dirty tests |
| Query bindings | base/MySQL/PostgreSQL/SQLite query grammars | `Database{Query,MySql,MariaDb,Postgres,SQLite}QueryGrammarTest.php` |
| Console output | `src/database/src/Console/{ShowCommand,TableCommand}.php` | new `Hypervel\Tests\TestCase`-based `tests/Database/DatabaseConsoleJsonTest.php` |
| Telescope entry storage | `src/telescope/src/Storage/DatabaseEntriesRepository.php` | `tests/Telescope/Storage/DatabaseEntriesRepositoryTest.php` |
| Telescope normalization | `src/telescope/src/ExtractProperties.php`, `src/telescope/src/Watchers/{EventWatcher,ModelWatcher,RequestWatcher}.php` | new `tests/Telescope/ExtractPropertiesTest.php`, `tests/Telescope/Watchers/{EventWatcherTest,ModelWatcherTest,RequestWatchersTest}.php` |
| Telescope client redaction | `src/telescope/src/Watchers/ClientRequestWatcher.php` | `tests/Telescope/Watchers/ClientRequestWatcherTest.php` |
| Package metadata | `src/foundation/src/PackageManifest.php` | `tests/Foundation/FoundationPackageManifestTest.php`, `tests/Testing/PHPUnit/TestStateRegistrarsTest.php` |
| Testbench package metadata | `src/testbench/src/Foundation/PackageManifest.php` | `tests/Testbench/Foundation/{PackageManifestTest,PackageManifestPackageTesterTest}.php` |

## Testing plan

Run every changed/new test file immediately after editing it. Use small local depth builders inside tests; do not add production fixture APIs solely to manufacture nested values.

### Support, request, and validation

- Default and explicit `Support\Json` max-depth encode/decode round trips reconstruct exactly; one level over fails encode.
- Decode/validate accept the same maximum; non-positive/`PHP_INT_MAX` depth and unsupported validate flags raise native `ValueError` rather than helper `TypeError`.
- `validate()` returns true/false for valid/malformed JSON and honors `JSON_INVALID_UTF8_IGNORE`.
- `Jsonable` receives explicit and default flags, including unescaped Unicode and throwing behavior; document/test that depth remains object-owned.
- Arrayable and ordinary scalar behavior remains green.
- `Str::isJson()` and `Stringable::isJson()` accept 512 containers and reject 513, pinning both sides of the public boundary.
- `AssertableJsonString` and `TestResponse::decodeResponseJson()` accept a 512-container JSON response while preserving the friendly invalid-JSON failure path. `TestResponse::dump()` still emits decoded objects rather than associative arrays and falls back to raw invalid bytes.
- `Filesystem::json()` and `FilesystemAdapter::json()` accept 512 containers and reject 513. Keep existing malformed-default-null coverage and add throwing-flag coverage where it is not already pinned; adapter missing-file behavior remains null.
- `Support\Composer::modify()` writes a 512-container callback result and its next `modify()` reads the exact structure. A 513-container result throws before replacement and preserves the original file byte-for-byte; existing formatting, mode, and malformed-input coverage remains green.
- `Collection::toJson()` output round-trips through default `Collection::fromJson()` at the Support ceiling; one level above fails. Jsonable-item conversion in Collection and `Arr::from()` accepts the same ceiling. Build these boundaries from `Support\Json::MAXIMUM_NESTING_DEPTH` so behavior—not a duplicated constant—guards package drift.
- File maintenance payloads, JSON session attributes, `JsonResponse` data/encoding-option changes, outgoing client-request data, XML parsing, and Inertia page props each round-trip at 512 containers and reject one level above.
- Build the XML boundary fixture from 255 levels of repeated same-named siblings: SimpleXML projects each sibling pair through an array, producing 512 JSON containers within libxml's default element-depth limit. A plain element chain cannot reach this boundary. At 512 containers, the current null decode causes a return-type `TypeError`; the Support codec makes the document readable. Adding one innermost attribute produces 513 containers: the current encode `false` causes a nested `json_decode()` parameter `TypeError`, while the Support codec raises `JsonException`. Do not add `LIBXML_PARSEHUGE` or pin intermediate encoded bytes.
- Inertia deliberately replaces the nested native-call `TypeError` on over-depth values with encode-side `JsonException`. Assert that Inertia lets this `JsonException` propagate rather than converting it to `Not a valid Inertia response.`; its assertion-only catch remains unchanged.
- Pin the serialized-closure boundary exactly: 510 array containers in a public exception value become 511 in the named-parameter map and 512 in the complete envelope. The command must emit it, and the shared decoder must reconstruct the original exception class and exact value; 509 already works and 511 deliberately degrades before emission, so either neighboring value would miss the regression.
- Central decoder coverage owns malformed output/envelopes, gzip suffixes, remote exception reconstruction, binary/false results, and unserialization failures. The command's six test decoders use throwing Support decode. ProcessDriver and Testbench retain thin success/failure delegation tests plus their non-envelope branches; Testbench proves non-Closure raw output preserves gzip-marker bytes.
- Request `array`, `object`, and `collection` casts accept valid JSON at the maximum and reject malformed, empty, and over-depth raw input with `JsonException`.
- The default validated form-request path uses the `json` rule and the same maximum.
- Public validator and compiled plan paths both accept the maximum, reject one level over, and return validation failure—not an exception—for empty/malformed fields.

### Eloquent

- Default primitive JSON casts round-trip exactly at 512 containers and reject a 513-container write contextually.
- Default decode distinguishes valid `null`, stored `''`, and malformed non-empty JSON.
- Custom decoder receives `''` unchanged and may return null; custom encoder/decoder reset coverage remains green.
- Every one of the eight class-cast writers rejects encoder `false` with model/key context before storage or encryption.
- A custom encoder that returns exact `false` follows that same contextual failure path; do not add callback-result wrappers or worker-global error state for an invalid callback that returns no encoded string and sets no JSON error.
- `AsFluent` accepts an object returned by a custom decoder; encrypted ArrayObject/Collection and AsDataObject return null for successfully decoded wrong shapes rather than raising constructor/type errors.
- `AsDataObject` accepts an empty decoded map (`{}` or `[]` under associative decoding) and proves custom codec participation on read/write.
- JSON-path assignment rejects before storing/encrypting `false`; malformed nested reads surface `JsonException`, not a return-type error.
- Add a text column to the cross-engine JSON-cast fixture for intentionally malformed stored bytes; database JSON/JSONB columns reject those bytes before Eloquent can exercise repair.
- A valid assignment can replace malformed original JSON through Eloquent.
- Good ciphertext containing malformed JSON can be replaced.
- Undecryptable ciphertext raises `DecryptException` and leaves the raw original unchanged.
- Existing valid dirty/equivalence behavior stays green.

### Query and console

- Exercise all five grammar methods with an unencodable binding; include MariaDB's inherited MySQL path. Include a depth failure so both main native failure classes are pinned without duplicating every case at every site.
- Call each preparation method directly and assert it raises `JsonException`.
- Small probe subclasses expose each protected console JSON renderer, use `OutputStyle`/`BufferedOutput`, and assert invalid metadata raises `JsonException` rather than a Symfony `TypeError`; valid output remains unchanged.

### Telescope

- Normal and exception entries store and read exact 512-container complete content. A top-level field that pushes complete content past that ceiling is replaced with `Purged By Telescope`; two overflowing fields in one entry are both replaced and the final content remains readable.
- Depth-heavy exception context is purged while class, message, occurrences, visibility, and tags remain valid. If a non-depth-unencodable same-family exception follows a persisted visible exception, the throw leaves the original row visible and byte-for-byte unchanged, publishes no replacement or tags, and prevents an ordinary entry from the same batch from being stored. Do not invent an insert-failure seam solely to test the database transaction primitive.
- A complete encode that first encounters depth but whose field pass then encounters INF/NAN or another non-depth defect rethrows that later error. This pins that depth recovery never hides instrumentation defects.
- Updating retained 512-container content preserves every original field while merging changes. Malformed or wrong-shape stored content raises and remains byte-for-byte unchanged; invalid UTF-8 substitution stays green.
- A depth-heavy changed field is purged and a later update is still applied.
- `ExtractProperties`, the plain-object EventWatcher branch, and RequestWatcher view data preserve supported nested object state and raise a precise `JsonException` when native object encoding fails.
- Reusing a stored ModelWatcher hydration entry decodes through the shared storage contract and increments its fixed count without changing queue/coroutine behavior.
- Structured and raw JSON client requests redact a hidden field at 511 containers. Malformed and 512-container `/json` and `+json` requests are purged and never retain the raw secret.
- Headerless shallow object/array JSON remains masked; a 512-container headerless body prefixed by a tab and newline is purged without retaining the secret, pinning the failure-only sniff and exact whitespace set.
- A supported `withBody()` URL-encoded request redacts default and nested hidden fields, including bracket notation. An explicit plain-text body remains raw.
- Structured payloads that cannot be encoded are purged. Masked truncation uses the encoded masked bytes and never contains the original secret; existing exact size-limit behavior stays green.
- Client responses and application responses retain and mask 511-container arrays, purge 512-container arrays while keeping the entry storable, and retain the existing redirect/plain-text/empty/HTML fallbacks. Standalone `JsonResponse::getData()` remains supported at 512 because it has no Telescope envelope.
- Request session and programmatically merged input at 512 containers, lifted Event/ExtractProperties payloads, and other watcher-owned deep top-level fields exercise the storage backstop: only that field is purged and unrelated entries/tags survive.
- Test repository failures directly because `Telescope::executeStore()` intentionally reports and isolates them from the application. Keep one end-to-end assertion that the request completes while a bad diagnostic batch is not published.

### Package manifest and test cleanup

- Missing root/installed files remain clean.
- Root wildcard returns before parsing malformed installed metadata.
- A specifically ignored formatted package name skips invalid version/`extra.hypervel`.
- Invalid JSON, non-array root/`packages`, malformed/nameless entries, bad version, and bad package/root `extra.hypervel` throw precise exceptions naming the path and failing location.
- A non-array parent `extra` remains tolerated because it contains no addressable `extra.hypervel`; an explicitly present non-array `extra.hypervel` fails.
- Valid package/root `extra.hypervel` values remain consumer-owned and retain scalar/array behavior.
- Explicit `build()` over an existing valid cache fails before write and preserves that cache byte-for-byte.
- Keep the committed `tests/Foundation/Fixtures` base path read-only. Add one `ParallelTesting::tempDir('FoundationPackageManifestTest')` scratch root; place the manifest and every generated Composer root beneath it, recreate/delete the whole root through `Filesystem` in setup/teardown, and remove direct system-temp paths and the manual directory ledger.
- Replace TestState's malformed-does-not-throw case with malformed root/installed fail-loud cases. Assert no registrar runs before failure, while missing files remain tolerated.
- Testbench accepts an absent root package, preserves the formatted `testbench/example` root key, and rejects malformed syntax, scalar roots, empty/formatted-empty names, and invalid explicit `extra.hypervel` with the same package-shape rules as installed discovery.
- Use a `ParallelTesting::tempDir('PackageManifestTest')` scratch root for Testbench's manifest output instead of a direct system-temp path, while retaining its committed read-only fixtures.
- Package-tester subprocess coverage builds from a scratch package root, proves malformed metadata exits nonzero with the native error in stderr, and proves no manifest is published.

## Documentation, performance, and compatibility audit

- Update only the incorrect validation/casting example in user docs. Codec internals, grammar flags, Eloquent's stored-empty convention, and package-manifest parsing are method/PR details rather than new user workflows.
- Record in the PR/change summary: corrected explicit/default depth units; Collection `fromJson()` and `JsonResponse::getData()` defaults widen by one native level while explicit depths remain native; `JsonResponse::setEncodingOptions()` no longer replaces a supported deep payload with null; `Jsonable` default flags now produce unescaped Unicode; serialized-closure command/result ownership moves to Concurrency and supported maximum-depth envelopes become readable; Telescope storage purges depth-overflowing top-level fields, rejects other unreadable content, and prevents supported JSON/form request paths from retaining configured secrets through raw fallback; malformed Composer metadata now fails package discovery and Testbench startup; missing metadata remains supported.
- Compare each port with its current owner: Laravel framework 13.x, Telescope 5.x, Orchestra Testbench, or Inertia Laravel as applicable. Preserve public/protected signatures, named arguments, method order, callbacks, and normal output except for approved correctness changes and typed anonymous-caster parameters.
- `Str::isJson()` and cold framework-owned file/round-trip operations are the added production consumers of Support's depth translation. Inertia, `AssertableJsonString`, and `TestResponse` changes are test-only. Successful-path overhead is limited to one branch and integer increment for Support decode/validate—including process-envelope reads already dominated by process creation and IPC—inline depth expressions/literals at native decode calls, exact `false`/shape checks in Eloquent writers/readers, and a non-throwing try boundary around original-value comparison. Telescope adds URL-encoded parsing only on its existing raw form-body path. Its normal structured path remains two traversals and oversized truncation drops from three to two; the default oversized purge path rises from one to two traversals and materializes masked encoded bytes so its limit applies to retainable data. Application responses remove one JSON traversal. Storage's per-field passes run only after a depth failure; successful entries still encode once. Exception chunks add the bounded transaction and sorted family keys described above; ordinary Telescope batches do not. There is no other added query, I/O, allocation-heavy abstraction, container lookup, lock, coroutine state, or worker memory.
- Failure-only exception construction is intentional. Package metadata and Testbench work remain cold bootstrap/build work.
- Remove superseded fallbacks, silent filters, duplicate JSON-path encoding, unused request `asJson()`, and stale comments/tests in the same implementation. Do not leave compatibility shims for unreleased 0.4 behavior.

## Verification and completion

1. Run each changed/new test file immediately.
2. Run the focused Collections, Support, Filesystem, Foundation HTTP/maintenance, Validation, Http, Session, Inertia, Concurrency/process transport, Database grammar/Eloquent/console, Telescope storage/watcher, Foundation PackageManifest, Testing registrar, and Testbench PackageManifest suites.
3. Run the real SQLite, PostgreSQL, MySQL, and MariaDB database integration groups because JSON column behavior, repair fixtures, and grammar inheritance are engine-sensitive.
4. Run `composer test:testbench`, then run `composer fix` once at the complete checkpoint. If either fails, correct with targeted checks, then run the failed and remaining script entries as required by `AGENTS.md`.
5. Freshly review every diff through all callers/callees for Eloquent repair safety, custom codec behavior, Telescope storage/redaction failure policy, package ignore ordering, strict typing, Laravel-style APIs, stale code/comments/docs, overengineering, and hot-path cost.
6. Request adversarial peer code review and loop until signoff before commit/PR.

Completion requires every accepted 512-container value to be readable at the same public limit, Telescope to purge only top-level fields that exceed its complete-content ceiling while non-depth invalid writes fail before side effects, valid replacements to repair only readable/decryptable corrupt JSON, supported structured Telescope request bodies never to retain configured secrets through raw fallback, package discovery to distinguish missing from corrupt metadata, all supported engines to pass, and no speculative mechanism or dead path to remain.

## Primary references

- [PHP `json_encode`](https://www.php.net/manual/en/function.json-encode.php)
- [PHP `json_decode`](https://www.php.net/manual/en/function.json-decode.php)
- [PHP `json_validate`](https://www.php.net/manual/en/function.json-validate.php)
- [Composer schema](https://getcomposer.org/doc/04-schema.md)
- [Laravel 13.x Eloquent JSON codec](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Eloquent/Casts/Json.php)
- [Laravel 13.x Eloquent attribute casting](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php)
- [Laravel 13.x package manifest](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Foundation/PackageManifest.php)
- [Laravel 13.x serialized-closure command](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Concurrency/Console/InvokeSerializedClosureCommand.php)
- [Laravel Telescope 5.x](https://github.com/laravel/telescope/tree/5.x)
- [Orchestra Testbench](https://github.com/orchestral/testbench-core)
- [Inertia Laravel](https://github.com/inertiajs/inertia-laravel)
