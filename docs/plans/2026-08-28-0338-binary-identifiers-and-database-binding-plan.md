# Binary Identifier Codec and Database Binding Correctness

## Objective

Make UUID and ULID binary handling correct for the complete 16-byte value domain and make `AsBinary` reliable on every supported PDO database, especially PostgreSQL, without changing Laravel-shaped APIs or making the query builder cast-aware.

The implementation will add one explicit public database value object, keep automatic cast handling at the Eloquent save boundary, and otherwise use existing codec, connection, grammar, and PDO ownership points. It will not add a binding registry, generic cast-preparation contract, parser normalization layer, worker state, configuration, facade, helper, or schema inspection.

## Verified behavior and root causes

### Binary codec routing

UUIDs and ULIDs both have an exact 16-byte binary representation. `BinaryCodec::isBinary()` instead answers a different public question: whether arbitrary content contains a NUL byte or is invalid UTF-8. A valid 16-byte identifier can therefore return `false` from `isBinary()`.

The deterministic UUID `21107c1e-6448-43c2-b80b-40491d165946` has bytes `21107c1e644843c2b80b40491d165946`; those bytes contain no NUL and form valid UTF-8, so the current heuristic returns `false`. Exact enumeration with a UTF-8 DFA gives these false-negative probabilities:

- uniform random 16-byte values: `8.500704276080103e-05`, or about 1 in 11,763.73;
- RFC 4122 UUIDv4 values with the version and variant bits fixed: `6.163036124000403e-05`, or about 1 in 16,225.77.

The reported “roughly 1 in 11,800 UUIDs” is therefore correct for uniformly random 16-byte values, but UUIDv4 values are closer to 1 in 16,226.

Hypervel's nonblank false-negative sample currently round-trips only because Symfony UID deliberately lets `Uuid::fromString()` and `Ulid::fromString()` accept their binary forms. The route is still structurally wrong and relies on a permissive downstream parser. The concrete Hypervel data-loss bug occurs before parsing: `blank()` treats a nil identifier's sixteen NUL bytes, and other trim-blank 16-byte payloads, as blank and returns `null`.

Custom codecs currently receive neither null nor blank-string values because the blank guard precedes custom dispatch. Overrides of the built-in names have that same behavior. That contract must remain unchanged; only unoverridden built-in UUID and ULID formats may recognize exact 16-byte input before the blank return.

`formats()` declares `list<string>` but `array_unique()` preserves keys. Registering an override for `uuid` and then a new `hex` codec currently returns `[0 => 'uuid', 1 => 'ulid', 3 => 'hex']`, which violates the declared list contract.

### PostgreSQL binary writes and reads

`AsBinary::set()` stores encoded bytes as an ordinary PHP string. `PdoConnection::bindValues()` therefore binds them as `PDO::PARAM_STR`. This is not an adequate binary signal on PDO_pgsql:

- some payloads fail with SQLSTATE `22021`;
- NUL-containing strings can be stored as empty or rejected;
- sixteen backslash bytes (`0x5C`) were reproduced as a silent write of only eight bytes.

Binding the same string as `PDO::PARAM_LOB` round-trips exactly on PostgreSQL, MySQL, MariaDB, and SQLite. Binding every string as LOB is not viable: it breaks ordinary PostgreSQL parameters for typed columns including UUID, JSONB, dates, and numerics. Binary intent must therefore be explicit per binding.

PDO_pgsql returns `bytea` values as seekable streams positioned at offset zero. The current `AsBinary::get()` passes that resource to `BinaryCodec::decode()`, whose parameter is `?string`. A normal stream read advances to the end and a second position-relative read is empty, so the cast must read from offset zero on every access.

The PDO stream remains the model's raw attribute, and `replicate()` copies that same resource into the new model. Leaving it at EOF after a cast read makes a later save bind an empty LOB without an error. The cast must therefore rewind the borrowed PDO stream after reading it so raw attribute copies remain reusable. This needs one direct `rewind()`, not general stream-position preservation or metadata machinery: PDO_pgsql supplies a seekable stream positioned at zero.

Changing `PDO::ATTR_STRINGIFY_FETCHES` is rejected because it changes unrelated result types globally rather than fixing the cast boundary.

### Binding and query-observation flow

Binding objects already survive `Query\Builder::castBinding()`, `cleanBindings()`, and `Connection::prepareBindings()` unchanged. This was verified end to end. No new builder path is needed.

Eloquent model `create`/`save`, `saveOrIgnore`, and instance updates converge on three copied statement-value arrays in `Model::performInsert()`, `performInsertOrIgnore()`, and `performUpdate()`. Wrapping binary values there can affect PDO binding without replacing values in the model's raw attributes, originals, dirty tracking, or changes.

Query-builder `where`, bulk `update`, and `upsert` deliberately bypass model casts. A public explicit binary value is required for already-encoded bytes in those APIs; teaching the builder to discover model casts would be a broad and ambiguous behavioral change.

`QueryExecuted`, query logs, before-executing callbacks, and `QueryException` receive the raw binding array before `prepareBindings()`. The binary marker may therefore remain visible: binding collections are mixed data, its public type truthfully records intent, and its string conversion keeps exception interpolation usable. Consumers that call `prepareBindings()` still receive the marker unchanged, unlike `DateTimeInterface` and booleans, which that method normalizes.

Telescope's query watcher is one such consumer. It routes every prepared non-null, non-numeric binding through a protected string-only quoting method, so both the new marker and existing PDO-supported resource bindings currently throw `TypeError`. The watcher must pass markers through the connection's shared escaping contract, convert live and closed resources to the same non-consuming identity strings used by query-grammar raw SQL rendering, and retain its current redaction of unescapable plain strings and propagation of unexpected `TypeError`s.

`Query\Grammars\Grammar::substituteBindingsIntoRawSql()` currently converts live and closed resources to their identity strings. Two tests explicitly pin that behavior. Reading arbitrary resources for display would be unsafe because they can be closed, nonseekable, blocking, consuming, or very large. Keep resource rendering unchanged; the new replayable binary value is the accurate raw-SQL path.

### Laravel reference

The read-only reference is `examples/laravel/framework`, branch `13.x`, commit `9b21ce0a9bb2b2599978bc0b0df4abea952ad31c` (`v13.26.1-48-g9b21ce0a9b`). It confirms three upstream defects:

1. `Illuminate\Support\BinaryCodec` routes a false-negative 16-byte UUID to Ramsey `Uuid::fromString()`, which throws instead of using `Uuid::fromBytes()`.
2. Its leading `blank()` guard also loses nil UUID and nil ULID binary values.
3. Its PostgreSQL `AsBinary` path has the same string-binding and stream-read failures.

Laravel issue `#10847` independently records the historical need for explicit binary parameter typing. The Laravel reference remains untouched. These findings must be included in the final maintainer handoff, not converted into compatibility workarounds in Hypervel.

Hypervel's `Str` UUID methods are present, but their value types intentionally follow Symfony UID instead of Ramsey UUID. `Str::orderedUuid()` returns Symfony UUIDv7, whereas Laravel produces a timestamp-first COMB UUIDv4. This is a real public porting difference, not a missing-method bug and not a reason to add Ramsey compatibility machinery.

## Final design

### 1. Use structure for built-in UUID and ULID routing

Modify `src/support/src/BinaryCodec.php` to define private typed constants for the two built-in formats and their shared binary length:

```php
private const array BUILT_IN_FORMATS = ['uuid', 'ulid'];

private const int BINARY_LENGTH = 16;
```

Add one private predicate shared by `encode()` and `decode()`:

```php
private static function isBuiltInBinary(mixed $value, string $format): bool
{
    return is_string($value)
        && strlen($value) === self::BINARY_LENGTH
        && in_array($format, self::BUILT_IN_FORMATS, true)
        && ! isset(self::$customCodecs[$format]);
}
```

Each operation computes this once. It returns `null` for a blank value only when the predicate is false, performs custom dispatch in the existing position, and uses the computed result in the built-in match arm. Consequences:

- every unoverridden built-in 16-byte payload, including nil and all-space bytes, takes the binary path;
- UUID/ULID objects and textual values keep their existing parsing behavior;
- null, empty strings, and non-16-byte ordinary whitespace keep returning `null`; an exact 16-byte whitespace payload is part of the valid binary identifier domain and now round-trips;
- blank custom-codec input and blank input for a built-in override still return `null` before invoking the custom callable;
- invalid formats keep throwing for nonblank values and keep returning `null` for blank values;
- public `isBinary(mixed): bool` retains its Laravel-compatible content heuristic and signature.

Use `self::BUILT_IN_FORMATS` in `formats()` and restore its documented list shape:

```php
return array_values(array_unique([
    ...self::BUILT_IN_FORMATS,
    ...array_keys(self::$customCodecs),
]));
```

Do not add parser fallbacks, UUID-version validation, per-format strategy objects, or missing Ramsey methods. Length is the complete structural distinction for these built-ins.

Expand the public `isBinary()` method docblock with one concise limitation: it is a general content heuristic, not an identifier-format check, so callers should pass UUID/ULID values through `encode()` / `decode()` instead of using `isBinary()` to choose a parser. This documents the existing API honestly without changing its Laravel-compatible behavior.

### 2. Add an explicit immutable binary parameter

Add `src/database/src/BinaryParameter.php`:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Stringable;

readonly class BinaryParameter implements Stringable
{
    /**
     * Create a new binary parameter instance.
     */
    public function __construct(public string $value)
    {
    }

    /**
     * Return the binary parameter value.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
```

This is the only new public API. `readonly` enforces immutable binding-marker state; the public string makes the value transparent to listeners and extensions; `Stringable` makes diagnostic interpolation predictable. Keep the class open because subclassing does not weaken either binding path: both use `instanceof BinaryParameter` and unwrap the inherited value directly. Do not add an interface, facade, helper, factory, registry, mutable flag, or serialization protocol.

### 3. Bind only marked strings as LOBs

Modify `PdoConnection::bindValues()` so each `BinaryParameter` is unwrapped to its string and bound as `PDO::PARAM_LOB`. Preserve the existing integer, resource, and default-string branches for every other value. The intended local shape is:

```php
$parameter = $value instanceof BinaryParameter ? $value->value : $value;

$statement->bindValue(
    is_string($key) ? $key : $key + 1,
    $parameter,
    match (true) {
        $value instanceof BinaryParameter => PDO::PARAM_LOB,
        is_int($parameter) => PDO::PARAM_INT,
        is_resource($parameter) => PDO::PARAM_LOB,
        default => PDO::PARAM_STR,
    },
);
```

Do not change `prepareBindings()`, enable global PDO attributes, infer binary intent from content, or bind all strings as LOBs.

### 4. Render marked bindings through existing driver binary escaping

Modify `Connection::escape()` to detect `BinaryParameter` before its null and `$binary` branches and return `escapeBinary($value->value)`. This gives the marker the same driver-specific SQL literals already used by explicit binary escaping.

Widen the sole implementation signature in `src/database/src/Grammar.php` from `string|float|int|bool|null` to `BinaryParameter|string|float|int|bool|null`. `ConnectionInterface::escape()` and `Connection::escape()` already accept `mixed`, and Laravel's public grammar method is untyped, so Laravel-shaped overrides remain compatible. A subclass that copied Hypervel's former exact native union would need the new type, but preserving that Hypervel-specific typing is not a Laravel API constraint; the widened forwarder accurately states the values it delegates.

Do not modify query-grammar resource conversion or normalize markers out of query events, logs, callbacks, pretend results, or exceptions. Builder/event `toRawSql()`, `QueryException::getRawSql()`, pretend output, and logged raw-query generation converge on grammar substitution; the initial `QueryException` message keeps its existing direct `Stringable` interpolation. Test the grammar seam plus one public builder path instead of duplicating each consumer.

Update Telescope's existing `quoteStringBinding()` without renaming this upstream method. Import `BinaryParameter`, change its native `$binding` type to `mixed`, document the actual `BinaryParameter|resource|string` union, and retitle its docblock for non-numeric bindings. Convert live and closed resources to their identity strings before calling `Connection::escape()`; pass markers through unchanged so the connection remains the sole owner of binary-literal semantics. Keep catching only `RuntimeException`: plain unescapable strings remain redacted, while unsupported objects and broken-driver `TypeError`s remain visible. Do not read, rewind, buffer, or close watcher resources, catch `TypeError`, replace Telescope's distinct renderer with `QueryExecuted::toRawSql()`, or add a shared rendering abstraction.

### 5. Mark `AsBinary` values only in copied model statement data

Import `AsBinary` and `BinaryParameter` into `HasAttributes`. Add the missing `@return array<string, string>` docblock to public `getCasts()`: model initialization and `mergeCasts()` already normalize every `Stringable` / array declaration into the string-valued `$casts` property, while an incrementing key contributes the string returned by `getKeyType()`. This records the real runtime contract rather than adding a cast to satisfy static analysis.

Add a protected array-transforming helper that iterates the usually smaller statement-value array rather than every declared cast:

```php
/**
 * Prepare binary-cast attributes for database binding.
 *
 * @param array<string, mixed> $attributes
 * @return array<string, mixed>
 */
protected function prepareBinaryAttributesForDatabase(array $attributes): array
{
    $casts = $this->getCasts();

    foreach ($attributes as $key => $value) {
        $cast = $casts[$key] ?? null;

        if (! is_string($value) || $cast === null) {
            continue;
        }

        if ($cast === AsBinary::class || str_starts_with($cast, AsBinary::class . ':')) {
            $attributes[$key] = new BinaryParameter($value);
        }
    }

    return $attributes;
}
```

Place the helper directly after `getAttributesForInsert()`. This keeps Laravel's paired `getCasts()` accessor and `casts()` declaration hook adjacent while grouping the transformer with the statement-value boundary it prepares.

The exact class-or-argument prefix intentionally does not match an unrelated future class such as `AsBinaryFoo`, and it does not add speculative subclass behavior.

Call the helper only on copied values immediately before these statements:

- `performInsert()`: the array passed to `insertAndSetId()` or `insert()`;
- `performInsertOrIgnore()`: the array passed to `insertOrIgnoreReturning()`;
- `performUpdate()`: a prepared copy of `$dirty` passed to `update()`, leaving `$dirty` and later change synchronization untouched.

Do not mutate `$this->attributes`, originals, dirty state, changes, `getAttributesForInsert()`, or `getDirtyForUpdate()`. Do not create a general “prepare casts for database” extension point. Do not make binary-cast primary-key lookup or query-builder writes implicit: the documented `AsBinary` use remains an additional UUID/ULID column beside the normal model key, while explicit builder operations use `BinaryParameter`.

### 6. Read PostgreSQL streams without consuming the raw attribute

In `src/database/src/Eloquent/Casts/AsBinary.php`, preserve the current source of truth by assigning `$attributes[$key] ?? null` to a distinctly named local such as `$attribute`. When that local is a resource, retain its handle, read it from offset zero, and rewind the handle before passing the bytes to `BinaryCodec::decode()`:

```php
$attribute = $attributes[$key] ?? null;

if (is_resource($attribute)) {
    $attributeStream = $attribute;
    $attribute = stream_get_contents($attributeStream, offset: 0);

    // Keep the PDO stream reusable when raw attributes are copied and rebound.
    rewind($attributeStream);
}

return BinaryCodec::decode($attribute, $this->format);
```

The offset argument seeks to zero for every read, and the rewind restores the PDO-owned raw attribute to the position required for later raw LOB binding, including a replicated model save. Do not inspect stream metadata, preserve arbitrary incoming positions, add a failure guard, or throw a custom exception: PDO_pgsql supplies a seekable stream positioned at zero, and an unexpected `false` already fails naturally at `BinaryCodec::decode(?string)` with the relevant trace. No wrapper or copied stream is stored on the model.

Do not add an inline `@var string` narrowing for the stream read. It is unnecessary under the repository's PHPStan configuration and would falsely claim that the deliberate natural `false` failure is impossible.

### 7. Remove duplicate test cleanup

Delete the reflection-based `tearDown()` and `ReflectionClass` import from `tests/Database/DatabaseEloquentAsBinaryCastTest.php`. The repository's `AfterEachTestSubscriber` already calls `BinaryCodec::flushState()` after every test, so the local teardown is redundant and reaches into protected state unnecessarily.

While editing this test and `SupportBinaryCodecTest`, add the repository-required `: void` declarations to their existing test methods. Do not add a second static reset or broaden the cleanup.

## Public API and compatibility

Preserve all existing `BinaryCodec`, `AsBinary`, `Model`, query-builder, connection, and grammar method names and named arguments. `BinaryCodec::isBinary()` retains its public Laravel behavior. Existing custom codec call order and blank-input semantics remain intact.

The only public addition is `Hypervel\Database\BinaryParameter`. The `Grammar::escape()` union widening is additive for callers and compatible with Laravel's untyped method; the connection contract is already `mixed`. Model observers continue to see ordinary string/null raw attributes. Query observers may see the transparent marker in mixed binding arrays. Telescope renders it through the existing connection escape contract instead of rejecting it.

No new mutable static or singleton state is introduced. `BinaryCodec`'s existing boot-only custom codec registry remains owned and flushed exactly as before; the new constants are immutable worker-safe metadata.

Symfony UID remains the intentional dependency and return type for Hypervel `Str` UUID APIs. No methods are removed, renamed, or shimmed. `orderedUuid()` remains UUIDv7 because its implementation is already correct; only the missing compatibility documentation changes.

## Documentation

Follow `AGENTS.md`'s split between canonical guides, package README differences, and actionable porting guidance:

1. Add a “Binding Binary Values” subsection under “Running SQL Queries” in `src/docs/database.md`. Explain `BinaryParameter` as the explicit wrapper for already-encoded binary strings and show query-builder `where`, bulk `update`, and `upsert` usage. Keep encoding choice separate; the wrapper expresses binding intent.
2. Update the `AsBinary` section of `src/docs/eloquent-mutators.md` in two focused ways:
   - show `$table->binary('uuid', length: 16, fixed: true)` and the equivalent `ulid` column, because both formats have an exact 16-byte representation and `BINARY(16)` remains indexable across every supported driver while bare MySQL `binary()` becomes `BLOB`;
   - state that normal model saves apply the cast, but direct query-builder operations do not apply model casts and should use the database guide's binary parameter. Link rather than duplicate binding examples.
3. Add one concise `src/database/README.md` Differences bullet for the additive public `BinaryParameter` API Laravel lacks. Do not characterize routine PostgreSQL correctness or a bug fix as a framework difference.
4. Add `src/support/README.md` Differences bullets for Symfony UID value/factory/callback types and `orderedUuid()` UUIDv7 versus Laravel's Ramsey timestamp-first COMB UUIDv4. Move the existing `Ported from:` line below Differences so the README follows the required canonical order.
5. Add a UUID subsection and table-of-contents entry under “Other API Differences” in `src/docs/porting-from-laravel.md`. Give the actionable Symfony-versus-Ramsey type/method warning and the ordered-UUID version/ordering difference.
6. Add concise database porting guidance: wrap already-encoded binary values used in query-builder `where`, bulk `update`, or `upsert` calls with `BinaryParameter`, linking to the canonical database section.

Do not document the codec correction, nil handling, PostgreSQL stream handling, or automatic model-save binding as Laravel differences. Those are correctness fixes, not lasting user choices.

## Rejected machinery

Do not add:

- content-based binary inference at PDO or query-builder level;
- blanket `PDO::PARAM_LOB` binding or global PDO fetch/stringify attributes;
- a typed-binding interface hierarchy, registry, enum, facade, or helper;
- cast-aware query builders, schema inspection, SQL parsing, or primary-key special cases;
- a generic cast preparation lifecycle or subclass detection for `AsBinary`;
- global marker-normalization passes for logs, events, callbacks, pretend mode, or exceptions;
- automatic reading, cloning, buffering, or rewinding of arbitrary resource bindings;
- parser fallbacks, Ramsey compatibility methods, or UUID-version normalization;
- caches, coroutine context, locks, retained streams, or worker-lifetime mutable state.

Each would broaden behavior beyond the verified need, add overhead or ambiguity, or break existing Laravel-shaped APIs. The explicit marker and three existing statement boundaries are sufficient.

## Performance and regression analysis

- Built-in binary detection performs four constant-time operations: a string type check, an exact length check, a two-element built-in-format membership check, and a custom-codec map lookup. It avoids `str_contains()` and a full UTF-8 scan on the hot built-in route and is therefore no slower than the current heuristic.
- PDO binding adds one `instanceof` per value. Ordinary values take the same type branches and values as before.
- Model saves scan only the copied values in the pending insert/update statement, perform a cached cast lookup per key, and allocate one tiny immutable marker only for non-null `AsBinary` strings. They do not scan unrelated model casts, SQL, schema, observers, or arbitrary attribute contents.
- PostgreSQL stream materialization occurs only when an `AsBinary` attribute is accessed, reads exactly that value from offset zero, and performs one rewind so the borrowed raw stream remains reusable. No data is retained beyond the existing model/resource lifetime.
- Raw SQL rendering performs the same driver binary escaping already present. No I/O, global option, cache, or coroutine synchronization is added.
- Telescope adds one live-or-closed-resource check only while its query watcher formats a non-numeric binding; markers use the connection's existing type check. Resource display uses the existing identity string without reading or changing the stream.

## Tests

### Codec unit coverage

Update `tests/Support/SupportBinaryCodecTest.php`:

- replace the probabilistic `random_bytes(16)` assertion with deterministic inputs;
- name the valid-UTF-8/no-NUL regression fixture `UTF8_SAFE_BINARY_UUID` so its relevant property is explicit;
- assert that the known valid-UTF-8/no-NUL UUID bytes return `false` from public `isBinary()` while built-in encode/decode still round-trip them through the structural route;
- cover nil UUID and nil ULID encode, decode, and re-encode round trips;
- retain null, empty-string, non-16-byte whitespace, textual identifier, object, and invalid-format behavior;
- explicitly prove that a blank 16-byte payload still returns `null` before invoking a custom format or a built-in-name override, protecting their existing blank contract from the new built-in predicate;
- register a built-in override followed by a custom format and assert exact ordered list values, sequential keys, and `array_is_list()`;
- avoid a redundant rare-byte ULID branch because UUID and ULID share the one private structural predicate.

### Database unit coverage

Update `tests/Database/DatabasePdoConnectionTest.php` with one focused binding test that captures calls for a `BinaryParameter` beside ordinary string, integer, and resource values. Assert that only the marker is unwrapped, that it and the existing resource use `PDO::PARAM_LOB`, and that ordinary string/integer values retain `PDO::PARAM_STR` / `PDO::PARAM_INT`. This directly protects the complete modified match without creating a separate test per scalar type.

Place the focused binding test immediately before `testStatementProperlyCallsPDO()` so direct statement binding behavior remains grouped.

Update `tests/Database/DatabaseQueryGrammarTest.php` to assert:

- direct grammar substitution renders a `BinaryParameter` with the connection's driver-specific binary literal;
- `Query\Builder::toRawSql()` renders the same literal through the public path;
- existing live and closed resource identity tests remain unchanged.

Update `tests/Database/DatabaseEloquentAsBinaryCastTest.php` to pass a seekable stream containing binary identifier bytes, assert that two successive cast accesses return the same canonical identifier, and assert that the raw stream is positioned back at zero afterward. This directly proves both repeatable decoding and the invariant required when raw attributes are copied and rebound. The assertions are meaningful because `AsBinary::get()` returns a string/null, so `getClassCastableAttributeValue()` clears rather than serves `classCastCache` and invokes the cast on both accesses; do not change the cast to an object-returning shape that would invalidate this coverage. Use exception-safe resource closure. Keep existing codec validation and set/get coverage after removing redundant teardown.

Update `tests/Telescope/Watchers/QueryWatcherTest.php` with two regressions through a real SQLite connection:

- a `BinaryParameter` renders with the driver's binary literal;
- live and closed resources render as quoted identity strings, while the live stream remains open at the same position.

Keep the existing unescapable-string redaction and broken-driver `TypeError` tests unchanged.

### Cross-driver integration coverage

Add `tests/Integration/Database/DatabaseEloquentAsBinaryIntegrationTest.php` using `Hypervel\Testbench\TestCase` and `Hypervel\Foundation\Testing\RefreshDatabase`. Do not extend this directory's usual `DatabaseTestCase`: that base brings `DatabaseMigrations`, while this test needs neither migration behavior, a fresh migrated baseline, nor its driver-skip helpers, and `AGENTS.md` requires `RefreshDatabase` by default. In `afterRefreshingDatabase()`, create one table with an incrementing ID and these exact identifier definitions:

```php
$table->binary('uuid', length: 16, fixed: true)->unique();
$table->binary('ulid', length: 16, fixed: true);
```

The exact width follows the identifier domain and avoids MySQL's unindexable bare-`binary()` `BLOB` form. Define a file-local model with explicit table, empty guarded array, disabled timestamps, and `AsBinary::uuid()` / `AsBinary::ulid()` casts. Keep the lifecycle in one test method so schema setup occurs once and the sequential writes naturally exercise every affected path without repeated cross-driver DDL.

Run the shared test on MySQL, MariaDB, PostgreSQL, and SQLite. Use a normal `create()` record on every driver for the later explicit-marker lookup and bulk update. Because Laravel and Hypervel deliberately support `saveOrIgnore()` only on PostgreSQL and SQLite, gate only that subcase with an explicit `in_array($model->getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)` allow-list and use a separate distinct record. Do not emulate returning behavior or add a redundant unsupported-driver assertion for MySQL/MariaDB. Cover:

1. model create with NUL/invalid-UTF-8 bytes, hydration, and repeated cast access;
2. model update to the deterministic valid-UTF-8/no-NUL UUID, proving the structural route and update binding;
3. nil UUID and nil ULID model persistence and hydration;
4. successful `saveOrIgnore()` for a distinct cast model on PostgreSQL and SQLite, followed by an explicit-marker lookup and canonical-value assertions that prove the separate insert-or-ignore path persisted exact bytes where the public API is supported;
5. raw model attributes, originals, and `getChanges()` remaining string/null rather than `BinaryParameter` after saves;
6. explicit `BinaryParameter` lookup by already-encoded UUID bytes on every driver;
7. Eloquent/query-builder bulk update with marked bytes on every driver;
8. query-builder upsert with marked bytes and a unique binary key;
9. canonical UUID/ULID strings after every hydrate/read boundary.

This one integration file belongs in the shared database suite. `bin/run-database-tests.sh` already executes that directory for every supported driver, so no CI workflow or suite-discovery change is needed.

## Implementation order and file inventory

Edit one file at a time and run the nearest focused test after each coherent source/test pair:

1. `src/support/src/BinaryCodec.php` — structural built-in routing, list repair, and the public heuristic-limit docblock
2. `tests/Support/SupportBinaryCodecTest.php`
3. `src/database/src/BinaryParameter.php`
4. `src/database/src/PdoConnection.php`
5. `tests/Database/DatabasePdoConnectionTest.php`
6. `src/database/src/Connection.php`
7. `src/database/src/Grammar.php`
8. `tests/Database/DatabaseQueryGrammarTest.php`
9. `src/database/src/Eloquent/Concerns/HasAttributes.php` — the precise `getCasts()` return docblock and statement-value preparation helper
10. `src/database/src/Eloquent/Model.php`
11. `src/database/src/Eloquent/Casts/AsBinary.php`
12. `tests/Database/DatabaseEloquentAsBinaryCastTest.php`
13. `tests/Integration/Database/DatabaseEloquentAsBinaryIntegrationTest.php`
14. `src/telescope/src/Watchers/QueryWatcher.php`
15. `tests/Telescope/Watchers/QueryWatcherTest.php`
16. `src/docs/database.md`
17. `src/docs/eloquent-mutators.md`
18. `src/database/README.md`
19. `src/support/README.md`
20. `src/docs/porting-from-laravel.md`

No Composer autoload or package metadata change is required because the Database package already maps `Hypervel\Database\` through PSR-4.

## Verification

Run focused unit tests as their files change:

```bash
./vendor/bin/phpunit --no-progress tests/Support/SupportBinaryCodecTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabasePdoConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseQueryGrammarTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseEloquentAsBinaryCastTest.php
./vendor/bin/phpunit --no-progress tests/Telescope/Watchers/QueryWatcherTest.php
```

Run the new integration test against every supported database. Every case runs on all four drivers except the documented PostgreSQL/SQLite-only `saveOrIgnore()` subcase:

```bash
./bin/run-database-tests.sh mysql --filter=DatabaseEloquentAsBinaryIntegrationTest
./bin/run-database-tests.sh mariadb --filter=DatabaseEloquentAsBinaryIntegrationTest
./bin/run-database-tests.sh pgsql --filter=DatabaseEloquentAsBinaryIntegrationTest
./bin/run-database-tests.sh sqlite --filter=DatabaseEloquentAsBinaryIntegrationTest
```

At the final checkpoint run:

```bash
composer fix
git diff --check
git status --short
```

After any review-driven source change, rerun its focused tests and the affected cross-driver integration cases before repeating the final checkpoint.

## Review checklist

- Every exact 16-byte unoverridden built-in value bypasses blank detection and uses binary parsing.
- Blank behavior for custom codecs, built-in overrides, invalid formats, null, empty strings, and non-16-byte whitespace is unchanged; exact 16-byte built-in payloads are never discarded as blank.
- `isBinary()` remains a public content heuristic and `formats()` is a real list.
- Only explicit markers bind strings as `PDO::PARAM_LOB`; ordinary binding types do not change.
- Automatic marker creation happens only in copied model statement arrays for insert, insert-or-ignore, and update.
- No marker leaks into model attributes, originals, dirty/change tracking, or cached casts.
- PostgreSQL stream reads begin at zero on every cast access and restore the raw PDO stream to zero so later attribute copies can be rebound; no general position or defensive-exception machinery is added.
- Query listeners/logs retain raw mixed bindings; raw resources retain identity rendering.
- Telescope renders markers through `Connection::escape()`, renders live and closed resources without consuming them, retains plain-string redaction, and still exposes broken-driver type errors.
- Public method names, named arguments, protected Laravel extension points, and Symfony UID choices remain compatible.
- Documentation records only lasting public differences and actionable porting choices, not internal bug fixes.
- No generic machinery, mutable worker state, hidden I/O, parser normalization, or duplicate cleanup remains.
- Unit tests, all four database integration runs, `composer fix`, and `git diff --check` are green.

## Final maintainer handoff

The implementation handoff must separately enumerate the three reproduced Laravel framework findings: false-negative 16-byte UUID routing to Ramsey string parsing, nil UUID/ULID loss through `blank()`, and PostgreSQL `AsBinary` write/read failures. It must also distinguish the uniform 1-in-11,764 probability from UUIDv4's approximately 1-in-16,226 rate. No Laravel reference files are to be edited in this branch.

It must also report the reproduced Laravel Telescope resource-binding gap: upstream's untyped quoting method still passes a resource to `PDO::quote()` or `str_replace()`, and both branches throw `TypeError`. Hypervel's watcher correction safely renders resource identities because its PDO layer deliberately supports resource LOB bindings.

The handoff must also flag the planned `Str::uuid()` / `orderedUuid()` README and porting-guide documentation as closing a pre-existing public-difference gap discovered during this work, rather than silently presenting it as required by the binary bug fix. It remains in the plan because it is a real, actionable compatibility difference and the owner requested the best final codebase rather than minimum churn.
