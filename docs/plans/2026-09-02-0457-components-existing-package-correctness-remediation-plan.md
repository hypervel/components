# Existing-Package Correctness Remediation Plan

Status: Implementation, repository checks, and code review complete; ready to commit and open a pull request

## Objective

Resolve audit findings #21, #23, #26, #30–32, #34–35, and #82 against the current `0.4` branch. The work spans mail and notification metadata, declared attachments, anonymous broadcast routing, Collections package metadata, `Arr::join()`, resource collection guessing, multibyte substring replacement, identifier codecs, and `decrementEach()` validation.

These are framework bug fixes and one missing split-package dependency. Preserve Laravel's public API where it is correct, port current Laravel source and tests where applicable, and fix verified upstream defects locally rather than copying them. Do not add compatibility shims, new abstractions, worker-lifetime state, or defensive behavior for unsupported calls.

## Confirmed baseline

- The branch was created from `0.4`, including the clarified dependency-ownership rule in `AGENTS.md`.
- Current Laravel reference code is under `examples/laravel/framework` on its checked-out default branch.
- Finding #35 is already complete in `0.4`: built-in UUID and ULID codecs classify the unambiguous 16-byte representation by length before the generic content heuristic, while custom codecs retain the public `BinaryCodec::isBinary()` behavior. Existing unit and database-binding coverage includes UTF-8-safe, NUL-free binary identifiers. This slice will verify that state but will not reimplement it.
- No related `docs/todo.md` item belongs in this slice. The Foundation/Testing dependency cycle is an architectural change, and the GMP identifier-conversion investigation is a separate measured optimization proposal.

## Design decisions

### 1. Mail and notification metadata (#21)

#### Problem

`Envelope` accepts integer metadata and compares its wire-equivalent values as strings. `Mailable::metadata()` and `assertHasMetadata()` still narrow scalar values to strings, while direct mailable metadata uses strict unnormalized comparison. The same strict-types mismatch exists in `MailMessage`; passing an integer through `MailChannel` reaches Symfony's string-only `MetadataHeader` constructor unchanged.

The two adjacent mailable assertion helpers also retain older failure messages that omit actual values. Laravel's current messages provide useful expected/actual output, but Laravel needlessly invokes the user-defined `envelope()` method again after `renderForAssertions()` has already hydrated envelope tags and metadata into the mailable.

#### Implementation

In `src/mail/src/Mailable.php`:

- Document `$metadata` as `array<string, int|string|null>`.
- Widen only the scalar value of `metadata()` to `int|string|null`; retain `null` as the overload's existing default for array-form calls.
- Keep `hasMetadata()` at `int|string`. Continue using `isset()` so null entries do not become matchable values, and compare the stored and requested values after string normalization, matching `Envelope::hasMetadata()` and the emitted header representation.
- Widen `assertHasMetadata()` to `int|string`.
- Port Laravel's expected/actual diagnostics for `assertHasMetadata()` and `assertHasTag()`, but derive actual values from the already-hydrated `$this->metadata` and `$this->tags` arrays. This avoids an extra invocation of arbitrary application `envelope()` code solely to format a failure.
- Use strict tag membership checks so numeric-looking strings such as `01` and `1` remain distinct.
- Keep the existing cast in `buildMetadata()`, which is the Symfony header boundary. Do not filter nulls or add a guard for a scalar call that omits its value.

Use strict named-key membership in `SesV2Transport::listManagementOptions()` as the adjacent mail-package comparison cleanup. The parsed named keys are strings, so this changes no supported behavior.

Representative shape:

```php
/** @var array<string, int|string|null> */
protected array $metadata = [];

public function metadata(array|string $key, int|string|null $value = null): static
{
    // Existing array/scalar storage logic stays unchanged.
}

public function hasMetadata(string $key, int|string $value): bool
{
    return (isset($this->metadata[$key])
            && (string) $this->metadata[$key] === (string) $value)
        || (method_exists($this, 'envelope')
            && $this->envelope()->hasMetadata($key, $value));
}
```

In `src/notifications/src/Messages/MailMessage.php` and `src/notifications/src/Channels/MailChannel.php`:

- Document notification metadata as `array<string, int|string>`.
- Widen `MailMessage::metadata()` to `int|string`.
- Cast each metadata value only when constructing `MetadataHeader`.

These changes widen accepted valid input and preserve all existing string behavior. String normalization is constant-time and header casting occurs only while a message is being built.

#### Tests

- Extend `tests/Mail/MailMailableTest.php` with integer metadata supplied directly, in an array, and through an `Envelope`; verify rendering/sending, string-equivalent lookup, absent/null lookup behavior, integer assertions, missing and mismatched metadata diagnostics, empty and populated tag diagnostics, and strict numeric-looking tag comparison. Update the existing exact-message assertions in `testMailableMetadataGetsSent()` and `testMailableTagGetsSent()` rather than leaving their resulting failures unexplained.
- Use the hydrated arrays in the diagnostic implementation and prove failure-message formatting does not invoke `envelope()`. Use a fresh envelope-backed mailable and exactly one failing assertion in this focused case: hydration contributes one call and the failed `hasMetadata()` or `hasTag()` predicate contributes its existing fallback call, for two calls in total and never a third call to build the message.
- Extend `tests/Notifications/NotificationMailMessageTest.php` to prove integer metadata is retained without coercion before delivery.
- Add the Hypervel-specific `tests/Notifications/NotificationMailChannelTest.php` at the channel boundary. Prove integer metadata becomes a valid string-valued Symfony header rather than raising a strict-types `TypeError`, and cover the adjacent tag-header loop in the same focused test file. Laravel has no corresponding channel test to port.

### 2. Declared attachment lookup and resolver-time equivalence (#23 plus adjacent defect)

#### Problem

`Mailable::hasEnvelopeAttachment()` guards on `envelope()` but unconditionally calls `attachments()`. An envelope-only mailable therefore fails with an undefined-method error, while an attachments-only mailable is skipped and reports a false negative. Current Laravel has the same incorrect guard. The helper name also describes the old guard rather than the behavior it owns.

Investigation exposed a second correctness defect in `Attachment::isEquivalent()`, shared by current Laravel. It snapshots the comparison attachment's `as` and `mime` values before either resolver runs. `fromStorage()`, `fromStorageDisk()`, and `fromUploadedFile()` populate those values during resolution, so two identical late-resolving attachments compare unequal. The documented `hasAttachment(Attachment::fromStorageDisk(...))` path therefore returns false.

#### Implementation

In `src/mail/src/Mailable.php`:

- Rename the private helper to `hasDeclaredAttachment()`.
- Guard only on `method_exists($this, 'attachments')`.
- Preserve the existing `Attachable` conversion, object/list normalization, equivalence check, and fallback to legacy hydrated attachments.

```php
private function hasDeclaredAttachment(Attachment $attachment, array $options = []): bool
{
    if (! method_exists($this, 'attachments')) {
        return false;
    }

    $attachments = $this->attachments();

    return Collection::make(is_object($attachments) ? [$attachments] : $attachments)
        ->map(fn ($attached) => $attached instanceof Attachable
            ? $attached->toMailAttachment()
            : $attached)
        ->contains(fn ($attached) => $attached->isEquivalent($attachment, $options));
}
```

In `src/mail/src/Attachment.php`:

- Remove the early `with()` snapshot and its now-unused function import.
- Build each side's two-key comparison metadata inside that attachment's existing resolver callbacks, after any resolver-owned metadata mutation.
- Preserve caller-supplied `$options` precedence on the comparison attachment.
- Resolve each attachment exactly once; do not introduce a normalized value object or another abstraction.

```php
return $this->attachWith(
    fn ($path) => [$path, ['as' => $this->as, 'mime' => $this->mime]],
    fn ($data) => [$data(), ['as' => $this->as, 'mime' => $this->mime]],
) === $attachment->attachWith(
    fn ($path) => [$path, [
        'as' => $options['as'] ?? $attachment->as,
        'mime' => $options['mime'] ?? $attachment->mime,
    ]],
    fn ($data) => [$data(), [
        'as' => $options['as'] ?? $attachment->as,
        'mime' => $options['mime'] ?? $attachment->mime,
    ]],
);
```

Checking a declared data or storage attachment evaluates its resolver. That is required to compare its content and late-resolved metadata, and matches the cost already paid by Laravel and by envelope-style declared attachments. No I/O is added to unrelated mail operations.

`Hypervel\Mail\Mailables\Attachment` remains the existing namespace-consistency subclass of `Hypervel\Mail\Attachment`; no documentation or stub correction is needed.

#### Tests

- In `tests/Mail/MailMailableTest.php`, cover an envelope-only mailable without `attachments()` and an attachments-only mailable whose storage attachment matches before rendering; include a non-match. Retain the existing both-method/data-attachment coverage rather than duplicating path/data variants.
- In `tests/Mail/AttachmentTest.php`, prove equivalence for two identical `fromStorageDisk()` attachments and for two distinct `fromUploadedFile()` attachments created from the same `Hypervel\Http\Testing\File::createWithContent('example.pdf', 'content')->mimeType('application/pdf')` instance, matching the deterministic fixture already used in `AttachableTest`. These are the two public factory paths whose metadata is populated during resolution; existing path/data tests cannot expose the defect.
- Bind the existing filesystem contracts and assert the real resolver operations. Do not create a second storage fixture system.

### 3. Anonymous broadcast routing (#26)

#### Problem

When an on-demand notification has neither an explicit `broadcast` route nor a notification-defined channel, `BroadcastNotificationCreated::channelName()` falls back to the notifiable class and `getKey()`. `AnonymousNotifiable::getKey()` correctly returns null for notification fake/assertion compatibility, so the fallback silently constructs a malformed trailing-dot private channel.

#### Implementation

Keep route resolution at its current owner:

1. `broadcastOn()` continues to honor an explicit anonymous broadcast route.
2. A non-empty notification `broadcastOn()` result continues to win.
3. `channelName()` continues to honor `receivesBroadcastNotificationsOn()` before fallback.
4. Only when the remaining fallback notifiable is `AnonymousNotifiable`, throw `LogicException` with an actionable message requiring an explicit broadcast route or a notification-defined broadcast channel.
5. Preserve ordinary model/class fallback behavior, including unsaved models with null keys. Do not generalize the guard.

Document the new `LogicException` on the protected `channelName()` method that owns it.

```php
if ($this->notifiable instanceof AnonymousNotifiable) {
    throw new LogicException(
        'Anonymous notifiables must define an explicit broadcast route or the notification must define a broadcast channel.'
    );
}
```

For asynchronously handled broadcasts, this configuration error is reported by the worker when it resolves the channel rather than by the initiating request. Moving the check earlier would duplicate route and notification-channel resolution.

#### Tests

Extend `tests/Notifications/NotificationBroadcastChannelTest.php` to cover:

- anonymous notification without an explicit or notification-defined channel throws the exact `LogicException`;
- explicit anonymous `broadcast` route works;
- `receivesBroadcastNotificationsOn()` still wins;
- an ordinary keyed notifiable retains the class-and-key fallback.

Keep the existing `AnonymousNotifiable::getKey()` null assertion in `NotificationRoutesNotificationsTest`. Do not add a queued-broadcast integration test: serialization leaves the anonymous routes unchanged, so queueing changes only when this exact method executes and would otherwise retest unrelated queue/broadcast infrastructure.

### 4. Collections PHP 8.6 polyfill dependency (#30)

#### Problem

The Collections split package directly uses PHP 8.6's global `SortDirection` enum while supporting PHP 8.4. The monorepo root and Database package require `symfony/polyfill-php86`, but `hypervel/collections` does not, and none of its existing requirements guarantees the enum. A standalone supported Collections installation can therefore fail while loading or using sorting APIs.

#### Implementation

- Add `"symfony/polyfill-php86": "^1.36"` to `src/collections/composer.json`.
- Do not add a PHP 8.5 polyfill sweep to downstream packages: every identified consumer already requires Collections, which guarantees the functions it uses.
- Do not remove existing redundant declarations elsewhere; only repair the proven standalone installation gap.

#### Tests and verification

- Extend `tests/Support/PackageMetadataTest.php`, whose namespace already owns Support and Collections tests, to compare both Collections polyfill constraints with the root manifest. Widen the method description so it accurately covers both packages.
- Before editing the manifest, use an isolated temporary directory and Composer path repository with no monorepo autoloading to record that the current Collections package fails to provide `SortDirection`. After adding the dependency, repeat the same install and execute a sorting call using `SortDirection` successfully. This is a one-off discriminating verification step, not a network-dependent PHPUnit test.

### 5. `Arr::join()` one-item return type (#31)

#### Problem and implementation

With a non-empty final glue and exactly one item, `Arr::join()` returns the raw item despite its native `string` return type. Numeric and stringable values can therefore raise a `TypeError`, unlike the `implode()` and multi-item paths. Current Laravel retains the same raw return against its documented string result; Hypervel should fix the underlying defect rather than copy it.

Cast only the one-item result:

```php
if (count($array) === 1) {
    return (string) array_last($array);
}
```

This preserves strings byte-for-byte and applies the same normal PHP string conversion already used by adjacent join paths.

#### Tests

Extend `tests/Support/SupportArrTest.php` with one-item integer, float, stringable object, and string cases, while retaining the existing empty and multi-item cases.

### 6. Resource collection guessing with preserved keys (#32)

#### Problem and implementation

`TransformsToResourceCollection::guessResourceCollection()` reads `$this->items[0]`, so a valid collection keyed by an identifier has no index zero and fails as if it contained no model. Read the first value through the collection API instead:

```php
$model = $this->first();
```

Remove the nearby inaccurate `class-string<Model>` assertion, the now-unused `Model` import, and the resulting `function.alreadyNarrowedType` suppression; the natural `is_object()` plus `method_exists()` control flow analyzes cleanly. Normalize the `toResourceCollection()` parameter docblock to the already-imported short `JsonResource` name. Do not add an interface, callable wrapper, or runtime guard solely for static analysis.

#### Tests

- Add one keyed Eloquent collection case to `tests/Support/Traits/TransformsToResourceCollectionTest.php`.
- Add symmetric keyed-item cases to `tests/Pagination/PaginatorResourceTest.php` and `tests/Pagination/CursorResourceTest.php`.
- Retain existing ordinary-list, empty, non-object, missing-resource, and explicit-resource coverage. Do not add a second keyed base-Support case because Eloquent Collection inherits the same `first()` and trait implementation unchanged.

### 7. Multibyte `Str::substrReplace()` parity (#34)

#### Problem

Hypervel's simplified scalar implementation cannot accept its declared array forms and computes the suffix as `mb_substr($string, $offset + $length)`. That calculation is wrong for negative lengths; for example, replacing at offset 2 with length -1 inserts before the wrong suffix. Current Laravel has the complete array-capable implementation and uses a nested multibyte substring to match native negative offset/length semantics without byte corruption.

#### Implementation

Port the current Laravel default-branch implementation while preserving Hypervel's native signature and formatting:

- Delegate scalar-subject/array-offset-or-length combinations to native `substr_replace()` so PHP retains its native error behavior.
- Use a local multibyte replacement closure whose suffix is `mb_substr(mb_substr($string, $offset), $length)`.
- Normalize replacement, offset, and length arrays with `array_values()` so they are consumed positionally.
- Preserve every original subject key in the result.
- Use Laravel's defaults for missing positional replacement (`''`), offset (`0`), and length (`null`).

Do not replace this with native `substr_replace()` generally; that function is byte-based and corrupts multibyte offsets.

#### Tests

Port the current Laravel cases into `tests/Support/SupportStrTest.php`, adapted only for Hypervel strings and typing:

- negative offsets and negative lengths;
- multibyte scalar input;
- scalar and array replacements;
- array offset/length inputs;
- associative subject keys and positionally keyed option arrays;
- mismatched replacement/offset/length lengths;
- scalar subjects with array offset or length raising native `TypeError`.

Retain all existing Hypervel assertions. Run `tests/Support/SupportStringableTest.php` as wrapper coverage; no Stringable source change or duplicate array matrix is needed because Stringable delegates directly to `Str::substrReplace()` and owns only scalar string state.

### 8. UUID/ULID binary handling (#35)

No files change. Re-run the focused `SupportBinaryCodecTest` and existing database binary-binding coverage to confirm the completed implementation remains green. The generic public binary-content heuristic remains available to custom codecs; built-in UUID/ULID routing continues to use exact 16-byte storage length.

### 9. `decrementEach()` validation (#82)

#### Problem and implementation

Base `Query\Builder::incrementEach()` validates every amount before interpolating it into a raw arithmetic expression and rejects numeric array keys. `decrementEach()` interpolates without either guard. A nonnumeric amount can therefore reach raw SQL construction, and the two paired APIs accept different shapes.

Mirror the two local checks with decrement-specific wording before constructing each expression:

```php
foreach ($columns as $column => $amount) {
    if (! is_numeric($amount)) {
        throw new InvalidArgumentException(
            "Non-numeric value passed as decrement amount for column: '{$column}'."
        );
    }

    if (! is_string($column)) {
        throw new InvalidArgumentException(
            'Non-associative array passed to decrementEach method.'
        );
    }

    $columns[$column] = $this->raw("{$this->grammar->wrap($column)} - {$amount}");
}
```

Keep the checks local. A shared validator would add indirection to two short loops whose operation names and error messages differ. Eloquent Builder and Model already forward to this base method, so they need no production changes.

#### Tests

- Extend `tests/Database/DatabaseQueryBuilderTest.php` with decrement-specific nonnumeric/malicious amount and numeric-key failures using the same bare-builder shape as the adjacent increment validation tests. The unconfigured connection already proves validation occurs before update execution; do not add mock expectations. Give the two new tests and the two adjacent increment tests explicit `: void` return types without sweeping the rest of this legacy test file.
- Extend the existing `tests/Integration/Database/QueryBuilderTest.php` accounting scenario with valid decrement values covering integer, float, and numeric-string input without creating another schema fixture. Remove that scenario's arbitrary `string('name', 20)` test-only length while touching the schema, matching the repository's normal semantic-column rule.
- Retain existing Eloquent Builder and Model forwarding tests. Do not repeat the same validation at every forwarding layer; the base builder is the single owner.

## Documentation and compatibility

- No package README or `src/docs/porting-from-laravel.md` entry is warranted. These changes repair bugs, align existing accepted values, or restore a missing dependency; they do not create a lasting Laravel migration difference.
- Do not change `AnonymousNotifiable::getKey()`, add attachment-resolution configuration, or expose new public helper APIs.
- The deliberate structural improvements over current Laravel are limited to using already-hydrated mailable state for assertion diagnostics and fixing resolver-time attachment equivalence. Both preserve the public contract while avoiding an unnecessary callback and a verified false negative.

## Expected file changes

Production and metadata:

- `src/mail/src/Attachment.php`
- `src/mail/src/Mailable.php`
- `src/mail/src/Transport/SesV2Transport.php`
- `src/notifications/src/Channels/MailChannel.php`
- `src/notifications/src/Events/BroadcastNotificationCreated.php`
- `src/notifications/src/Messages/MailMessage.php`
- `src/collections/composer.json`
- `src/collections/src/Arr.php`
- `src/collections/src/Traits/TransformsToResourceCollection.php`
- `src/support/src/Str.php`
- `src/database/src/Query/Builder.php`

Remediation ledger:

- `docs/plans/2026-08-22-0604-components-04-audit-remediation-plan-codex.md`

Tests:

- `tests/Mail/AttachmentTest.php`
- `tests/Mail/MailMailableTest.php`
- `tests/Notifications/NotificationBroadcastChannelTest.php`
- `tests/Notifications/NotificationMailChannelTest.php` (new)
- `tests/Notifications/NotificationMailMessageTest.php`
- `tests/Support/PackageMetadataTest.php`
- `tests/Support/SupportArrTest.php`
- `tests/Support/SupportStrTest.php`
- `tests/Support/Traits/TransformsToResourceCollectionTest.php`
- `tests/Pagination/PaginatorResourceTest.php`
- `tests/Pagination/CursorResourceTest.php`
- `tests/Database/DatabaseQueryBuilderTest.php`
- `tests/Integration/Database/QueryBuilderTest.php`

No user-facing documentation changes. This plan and the master remediation ledger are the only documentation files; finding #35 should produce no source or test diff.

## Implementation order

1. Correct mailable/notification metadata types, header conversion, diagnostics, and focused tests.
2. Correct declared attachment discovery and resolver-time equivalence, then run mail tests.
3. Add the anonymous broadcast fallback error and routing tests.
4. Repair Collections package metadata and perform the standalone installation smoke test.
5. Correct `Arr::join()` and resource collection first-item lookup with their focused tests.
6. Port the current Laravel `Str::substrReplace()` implementation and tests.
7. Add base `decrementEach()` validation and extend the existing cross-driver integration scenario.
8. Reconfirm the already-complete identifier codec behavior.
9. Remove findings #21, #23, #26, #30–32, #34–35, and #82 from the master remediation ledger, leaving #160 as its only remaining implementation slice.
10. Run affected package suites, static analysis through the repository checkpoint, then self-review all callers, error paths, and final diffs.

## Verification plan

Run each changed test file immediately after its source/test edit:

```text
./vendor/bin/phpunit --no-progress tests/Mail/AttachmentTest.php
./vendor/bin/phpunit --no-progress tests/Mail/MailMailableTest.php
./vendor/bin/phpunit --no-progress tests/Mail/MailSesV2TransportTest.php
./vendor/bin/phpunit --no-progress tests/Notifications/NotificationMailMessageTest.php
./vendor/bin/phpunit --no-progress tests/Notifications/NotificationMailChannelTest.php
./vendor/bin/phpunit --no-progress tests/Notifications/NotificationBroadcastChannelTest.php
./vendor/bin/phpunit --no-progress tests/Support/PackageMetadataTest.php
./vendor/bin/phpunit --no-progress tests/Support/SupportArrTest.php
./vendor/bin/phpunit --no-progress tests/Support/Traits/TransformsToResourceCollectionTest.php
./vendor/bin/phpunit --no-progress tests/Pagination/PaginatorResourceTest.php
./vendor/bin/phpunit --no-progress tests/Pagination/CursorResourceTest.php
./vendor/bin/phpunit --no-progress tests/Support/SupportStrTest.php
./vendor/bin/phpunit --no-progress tests/Support/SupportStringableTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseQueryBuilderTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/QueryBuilderTest.php
./vendor/bin/phpunit --no-progress tests/Support/SupportBinaryCodecTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseEloquentAsBinaryCastTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/DatabaseEloquentAsBinaryIntegrationTest.php
```

Then run the affected package directories where practical, followed by the repository checkpoint once:

```text
./vendor/bin/phpunit --no-progress tests/Mail
./vendor/bin/phpunit --no-progress tests/Notifications
./vendor/bin/phpunit --no-progress tests/Support tests/Pagination
./vendor/bin/phpunit --no-progress tests/Database
composer fix
```

The database workflow must exercise the changed integration test across MySQL, MariaDB, PostgreSQL, and SQLite. The final `composer fix` owns formatting, both PHPStan configurations, the full parallel suite, Testbench package tests, and dogfood tests; do not duplicate those full checks at the same checkpoint.

Finally:

- inspect the complete diff and status for generated files, temporary Composer files, and unrelated changes;
- trace every changed method through direct callers and relevant Symfony/Laravel boundaries;
- verify no new static/singleton state, cache, lock, avoidable network round trip, or repeated hot-path work was introduced; declared storage-attachment comparison may perform its one existing resolver read per side, but must not resolve either attachment more than once;
- confirm the standalone Collections installation loaded `SortDirection` through its own declared dependency;
- confirm the attachment fix resolves each attachment exactly once and still honors explicit comparison options;
- confirm all exact failure messages and exception types match the final implementation.

## Completion criteria

- Every genuine retained finding in this slice is implemented or, for #35, reconfirmed as already complete.
- Two equivalent storage/upload attachments compare equal after their own metadata resolves.
- Envelope-only and attachments-only mailables can query declared attachments without a fatal or false negative.
- Integer metadata reaches both mailable and notification Symfony headers safely, and lookup semantics are consistent.
- Anonymous broadcasts cannot silently create a malformed fallback channel.
- Collections is independently installable on Hypervel's PHP floor with `SortDirection` available.
- One-item joins always satisfy their string return type; keyed collections and paginators guess resources correctly; substring replacement matches current multibyte Laravel behavior.
- `decrementEach()` rejects invalid raw-expression input before query construction and accepts valid numeric forms across supported databases.
- Focused checks and `composer fix` are green, and the final diff contains no workaround, stale code, unnecessary abstraction, or unrelated change.
