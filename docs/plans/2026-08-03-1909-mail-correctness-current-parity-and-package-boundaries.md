# Mail Correctness, Current Parity, and Package Boundaries

## Goal and boundary

Complete the Mail package audit by fixing the verified delivery, queue, attachment, fake, metadata, and test defects; porting the identified current Laravel changes; documenting the intentional SES v2-only and pooled-transport behavior; and closing routed Support, Contracts, and HTTP findings. Discovery is complete. Implementation, same-family findings exposed while editing, validation, final self-review, and code review complete the package audit.

## Audit principles

### 1. Verify before changing

A suspicious pattern is not an actionable finding until the audit establishes:

- the exact file and symbol;
- every relevant caller and callee across `src/` and `tests/`;
- the state or resource owner;
- the initialization, commit, use, and cleanup boundaries;
- a realistic production or test failure schedule;
- why current guards and tests do not prevent it;
- sibling implementations and same-family sites;
- relevant upstream behavior;
- the lowest correct fix boundary;
- a regression strategy;
- the performance and complexity effect of the proposed fix.

Use a focused probe when source reasoning cannot settle native or scheduler behavior. Do not repeatedly run the full suite hoping to reproduce a rare flake.

### 2. Fix the lowest inconsistent contract

Do not add local compensation when a shared lower-level contract is wrong. A caller catch is not enough when a typed filesystem method can return `false`; a per-consumer spawn catch is not enough when Engine exposes an ambiguous spawn contract; a proxy workaround is not enough when pool ownership is undefined.

After changing a lower-level contract, re-audit every affected caller and revisit completed packages that depend on it. Record cross-references in both the owning package and each affected package ledger entry.

### 3. Make ownership explicit

The component that acquires or registers a resource records the exact handle and releases that exact handle. Cleanup must not reconstruct identity from mutable state when the original handle can be retained.

Examples include coroutine IDs, timer IDs, process IDs plus incarnation checks, listener callbacks, pool leases, subscriber objects, stream handles, temporary filenames, signal watcher IDs, and channel tokens.

### 4. Make creation transactional

If code reserves capacity or publishes state before a later operation can fail, it must either finish creation or roll back every earlier change. Do not expose half-initialized objects, registered-but-dead pools, leaked wait-group counts, or published runtime paths without their cleanup owner.

### 5. Make cleanup exhaustive

Independent cleanup steps run even when an earlier step fails. The earliest operation or cleanup failure remains primary. Cleanup failures must not corrupt bookkeeping, skip unrelated cleanup, or turn a successful ownership transfer into a reported failure.

### 6. Bound only external progress

Use deadlines where progress depends on a process, socket peer, lock owner, IPC child, or external service that can disappear. Do not add arbitrary timeouts to ordinary internal coroutine joins once successful creation and ownership guarantee completion.

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

### 11. Preserve useful Laravel parity

Keep current Laravel public APIs, named arguments, protected extension points, method order, and conventional behavior unless a concrete Hypervel benefit justifies an approved difference. Historical PRs identify the complete changed-file surface; current Laravel `13.x` is the implementation and test reference.

## Evidence and references

- Hypervel source, tests, split/root metadata, Mail guide, and package README in this worktree.
- Current Laravel Framework `13.x` at `2c410561c21452de2f164caea64ab0fcac692a5d`.
- Originating Laravel changes, used for file discovery only:
  - PR #60865 / `b921032562`: `Mailer::later()` queue name (`Mailer.php`, `MailableQueuedTest.php`).
  - PR #59443 / `9c888ac1f9`: `assertHasNoAttachments()` (`Mailable.php`, `MailMailableTest.php`).
  - PR #60886 / `b79e2d621b`: SES v2 tenant name (`SesV2Transport.php`, `MailSesV2TransportTest.php`).
  - PR #58686 / `8aded48ec7`: single-label URLs (`Str.php`, `SupportStrTest.php`).
  - PR #51990 / `213a370b70`: render view content before Mailer callbacks (`Mailer.php`).
- Installed Symfony Mailer, Mime, and HttpFoundation source for sendmail defaults, MIME types, response constants, and transport behavior.
- Current Laravel Mail tests, including `AttachmentTest`, `MailableQueuedTest`, `MailMailableTest`, `MailMessageTest`, `MailSesV2TransportTest`, and `SupportTestingMailFakeTest`.

## State and ownership

- `MailManager` is a worker-lifetime singleton and caches named `Mailer` wrappers; its public registry/configuration mutators remain boot/test-only.
- Poolable transports remain lease-owned behind `TransportPoolProxy`; this work does not change borrowing, release, invalidation, or pool identity.
- A `Mailable` and its attachment resolvers are operation/job objects. Queue and MIME choices belong on those objects, not manager-global or coroutine context state.
- `MailFake` is tests-only facade state. Its selected mailer is a one-operation override, not a worker/request cache; the manager remains the owner of the current default.
- No new static, singleton-held mutable, coroutine-context, or external-resource state is introduced.

## Final finding set

| ID | Category | Severity | Decision |
|---|---|---:|---|
| `mail-01` | Queue defect | Major | Apply explicit queue names to delayed mailables and pass the queue factory to `Mailable::later()`. |
| `mail-02` | Security/parity defect | Major | Restrict URL attachments to HTTP(S). |
| `mail-03` | Queue contract defect | Minor | Accept `UnitEnum` on primary queue-selection APIs and normalize at Queueable. |
| `mail-04` | Attachment defect/performance | Major | Resolve storage once, preserve explicit MIME, and omit failed MIME detection. |
| `mail-05` | Current parity | Minor | Port `assertHasNoAttachments()`. |
| `mail-06` | SES v2 parity | Minor | Forward every non-empty `X-SES-TENANT-NAME`, including `"0"`, as `TenantName`. |
| `mail-07` | Configuration defect | Minor | Remove stale on-demand sendmail/log fallback reads without coupling to named mailers. |
| `mail-08` | Package boundary defect | Major | Remove unnecessary direct Notifications and Testing requirements; declare actual direct and optional dependencies. |
| `mail-09` | Test parity defect | Minor | Port and merge the missing current attachment, fake, embed, queue, assertion, and SES regressions. |
| `mail-10` | Test isolation/type defect | Minor | Use framework test bases, truthful return types, and worker-safe temporary directories. |
| `mail-11` | Dead/stale code | Minor | Remove an empty `try/finally`, use container `make()`, narrow suppressions, and retain the protected provider extension point with truthful documentation. |
| `mail-12` | Documentation defect | Minor | Add canonical docs/provenance and concise public guidance. |
| `mail-13` | Intentional difference | Minor | Record SES v2-only support in README, source, and tests. |
| `mail-14` | Pooled API documentation | Minor | Explain why pooled mailers expose a proxy rather than one stable concrete transport. |
| `mail-15` | Native type documentation | Minor | Document the supported string and resource data shapes where PHP requires native `mixed`. |
| `mail-16` | Callback ordering parity defect | Major | Render message content before callbacks so direct sends, mailables, and notifications can inspect or replace the final body. |
| `mail-17` | Facade metadata defect | Minor | Point `MailManager`'s mixin at its concrete `Mailer` so facade-documenter exposes the complete forwarded API. |
| `support-28` | Fake/facade defect | Major | Make `MailFake` intercept every side-effecting mail method, consume selection once, preserve queue and recipient metadata, and refresh facade annotations. |
| `support-29` | URL predicate parity defect | Minor | Port current single-label-domain support in `Str::isUrl()` for Mail, Stringable, and the framework `url` validation rule. |
| `support-30` | Fake assertion type defect | Major | Make MailFake's shared assertion helper accept the count and address shapes its public methods support. |
| `support-31` | Fake assertion type defect | Minor | Remove NotificationFake's non-callable string callback promise and port its complete current upstream unit suite. |
| `contracts-10` | Queue type consistency | Minor | Widen the Mail queue contract to the framework's `UnitEnum\|string\|null` identifier boundary. |
| `contracts-11` | Callback type consistency | Minor | Replace broad `mixed` callback parameters with Laravel's actual `Closure\|string` boundary across the Mail contract, concrete implementation, fake, and facade. |
| `http-27` | Completed-package docs defect | Minor | Correct HTTP README ordering and add its canonical guide link. |
| `filesystem-14` | Static-analysis type defect | Minor | Remove false `FilesystemAdapter` narrowing where supported pooled disks expose adapter methods outside the intentionally narrow contract. |

## Implementation design

### 1. Queue selection and delayed delivery

Widen the primary APIs only:

```php
public function queue(array|MailableContract|string $view, UnitEnum|string|null $queue = null): mixed
public function onQueue(UnitEnum|string|null $queue, MailableContract $view): mixed
public function later(DateInterval|DateTimeInterface|int $delay, array|MailableContract|string $view, UnitEnum|string|null $queue = null): mixed
```

Apply a non-null queue to the mailable, then always pass the queue factory to the queued mailable:

```php
if ($queue !== null) {
    $view->onQueue($queue);
}

return $view->mailer($this->name)->later($delay, $this->queue);
```

Use the same queue assignment in `queue()`. Accept `UnitEnum|string` in `queueOn()` and `laterOn()` as well so every public alias forwards the same identifier domain instead of rejecting values its delegate supports. Do not add `onQueue()` to the Mailable contract: generated queueable mailables receive it from `Bus\Queueable`, which owns `UnitEnum` normalization. Widen `Contracts\Mail\MailQueue` and facade/fake declarations consistently. At each deliberate contract boundary, use only the exact identifier-scoped suppression with a concise explanation; do not preselect an identifier or broaden the Mailable contract to silence analysis.

### 2. Render content before message callbacks

Port Laravel PR #51990 from the current `Mailer::send()` implementation exactly:

- remove the `First we need to parse the view...` comment from `send()` only;
- move the `Once we have retrieved the view content...` comment directly above `parseView()`;
- call `addContent()` before invoking the callback.

Do not change `render()`, where the original parse-view comment remains correct. The reorder fixes direct `send()`, `raw()`, `html()`, and `plain()` callbacks, `Mailable::send()` callbacks registered through `withSymfonyMessage()` or `Envelope(using: [...])`, and the notification `MailChannel` callback path. Every object involved is operation-local; the change adds no work or shared state.

Add two counterfactual regressions:

- in `MailMailerTest`, record the rendered HTML observed inside the callback, replace it, and assert both the observation and final replacement after sending;
- in `MailMailableTest`, exercise `Envelope(using: [...])` through a real mailable send, record the rendered body, replace it, and assert the final message.

Do not add a notification-specific ordering test: `MailChannel::buildMessage()` never sets a body, so it cannot distinguish the old and new order. Record the two accepted upstream consequences: attachments embedded while rendering now precede attachments added by mailable/notification callbacks in MIME order, and views now receive a bare message before envelope state and attachments are applied. Existing view embed behavior and attachment-index assertions remain valid and must be revalidated.

### 3. URL attachment safety and Support URL parity

At construction, accept only current HTTP(S) URLs:

```php
if (! Str::isUrl($url, ['http', 'https'])) {
    throw new InvalidArgumentException('Attachment URLs must use the http or https scheme.');
}
```

Port Laravel's current `Str::isUrl()` domain branch at the Support owner so valid single-label internal hosts are not rejected:

```php
(?:
    (?:
        (?:[\pL\pN\pS\pM\-\_]++\.)+
        (?:
            (?:xn--[a-z0-9-]++)     # punycode in tld
            |
            (?:[\pL\pN\pM]++)       # no punycode in tld
        )
    )                               # a multi-level domain name
        |
    [a-z0-9\-\_]++                  # a single-level domain name
)\.?
```

Copy this upstream branch verbatim, including its grouping and comments. Add the upstream Support cases and a Mail attachment case for `http://l/...`. Revalidate that the two Validation consumers now accept single-label hosts, which is the intended user-visible current-Laravel behavior. Do not add a Mail-local hostname exception or perform network preflight.

### 4. Storage attachment consistency

Resolve one adapter inside the modern resolver and reuse it:

```php
$storage = static::getStorageDisk($disk);

// The contract deliberately omits adapter metadata methods, which every shipped disk provides.
// @phpstan-ignore method.notFound
$mime = $attachment->mime ?? $storage->mimeType($path);

$attachment->as($attachment->as ?? basename($path));

if ($mime !== false) {
    $attachment->withMime($mime);
}

return $dataStrategy(fn () => $storage->get($path), $attachment);
```

In legacy `Mailable::buildDiskAttachments()`, use `options['mime']` when present and skip metadata I/O. Otherwise detect once and include `mime` only when detection returns a string. Preserve one content read and Symfony's fallback when MIME is unknown. Keep the truthful Filesystem contract type at both storage boundaries. The contract deliberately omits adapter metadata while every shipped disk implementation provides it, so place an exact `method.notFound` suppression on each `mimeType()` call with that concise WHY; do not restore the runtime-false `FilesystemAdapter` annotation. The suppression cannot prove the `false|string` return, making the false-MIME regressions load-bearing.

Do not widen the generic Filesystem contract, add adapter capabilities/reflection, catch unrelated failures, or redesign attachment streaming.

### 5. MailFake fidelity and one-shot selection

The fake stores only an explicit one-shot override; null means “resolve the manager's current default when an operation occurs.” Normalize enum names in `mailer()`, convert null/empty to null, and preserve `"0"`.

Use one helper because all side-effecting entry points consume the same invariant:

```php
protected function pullCurrentMailer(): string
{
    $mailer = $this->currentMailer ?? $this->manager->getDefaultDriver();
    $this->currentMailer = null;

    return $mailer;
}
```

Do not snapshot the default in the constructor. Route `ShouldQueue` before pulling so queued sends pull exactly once in `queue()`:

```php
protected function sendMail(array|Mailable|string $view, bool $shouldQueue = false): mixed
{
    if ($shouldQueue) {
        return $this->queue($view);
    }

    $mailer = $this->pullCurrentMailer();

    if (! $view instanceof Mailable) {
        return null;
    }

    $view->mailer($mailer);
    $this->mailables[] = $view;

    return null;
}
```

`queue()` pulls before its type check, then mirrors the real mailer by throwing `InvalidArgumentException` for non-mailables. It applies the selected mailer, applies every non-null queue through Queueable, and records it. This consumes invalid calls and is failure-safe when validation or `onQueue()` throws, without `try/finally`.

Declare side-effecting methods so `__call()` cannot reach the real transport or queue:

```php
public function html(string $html, Closure|string $callback): ?SentMessage
public function plain(string $view, array $data, Closure|string $callback): ?SentMessage
public function onQueue(UnitEnum|string|null $queue, Mailable $view): mixed
public function queueOn(UnitEnum|string $queue, Mailable $view): mixed
public function laterOn(UnitEnum|string $queue, DateInterval|DateTimeInterface|int $delay, Mailable $view): mixed
```

`raw()`, `html()`, and `plain()` pull and return null. Queue helpers delegate to the fake's `queue()`/`later()`. Leave read-only `render()` and `getSymfonyTransport()` forwarding; do not intercept configuration-only `always*` methods or invent delay state.

Add optional `?string $name` to fake `to/cc/bcc` and construct the same `Mailables\Address` as the real Mailer. Keep the one-argument Mailer contract unchanged.

Make the assertion helper accept the aggregate shape of all six callers:

```php
protected function prepareMailableAndCallback(
    Closure|string $mailable,
    array|callable|int|string|null $callback,
): array
```

Use `is_int()` for `assertSent()` and `assertQueued()` count routing because strings are addresses and the target methods require `int`. Keep the negative assertion unions unchanged. Repository PHPStan does not report this union mismatch; current upstream public-overload tests are the regression guard.

Regressions must cover dynamic manager defaults, null/empty/`"0"`/enum selection, ordinary and ShouldQueue sends, invalid send and queue consumption, both string and enum queue preservation across every queue alias, failure consumption, named recipients, and zero real transport/queue calls from all five previously forwarded methods. Relax the existing `getDefaultDriver()->once()` mock expectation: it pins the constructor snapshot being removed, while behavioral assertions must prove the one-shot selection contract and ShouldQueue's single pull.

Narrow `NotificationFake::assertSentTo()` from `callable|int|string|null` to `callable|int|null`. Callable strings remain accepted by `callable`; non-callable strings have no supported notification meaning and currently fail later at `sent(..., ?callable)`. Leave its `is_numeric()` check unchanged because the narrowed domain makes it equivalent to `is_int()`. Regenerate the Notification facade from that underlying signature. Port current Laravel's complete `SupportTestingNotificationFakeTest.php`; do not add a TypeError-only test that also passes before the fix. Record the separately missing `SupportTestingEventFakeTest.php` port in `docs/todo.md` rather than mixing unrelated Event work into this finding.

### 6. Current Mailable and SES v2 APIs

Insert `assertHasNoAttachments()` immediately before `assertHasAttachment()` in current upstream order.

For SES v2, copy options locally and add only a non-empty tenant name:

```php
if (($tenantName = $this->tenantName($message)) !== null) {
    $options['TenantName'] = $tenantName;
}
```

The helper reads `X-SES-TENANT-NAME`, maps only the empty string to null, and preserves every other valid value exactly. Cover present, `"0"`, absent, and empty headers. No shared transport options mutate.

### 7. Transport construction and source cleanup

On-demand sendmail/log transports read only their supplied config:

```php
'path' => $config['path'] ?? null,
'channel' => $config['channel'] ?? null,
```

Do not inherit another named mailer's configuration. Symfony owns the default sendmail command; log channel remains nullable. Retain Markdown's `'default'` and `[]` fallbacks because the inner settings are intentionally optional in a replaceable nested config array.

Replace six MailManager container array reads with `make()`, using an evidence-based local `@var` only where a canonical string key obscures the runtime type. Collapse `sendSymfonyMessage()` to its direct return. Replace broad PHPStan suppression only with a correct `@var` or identifier-scoped ignore.

Remove the same false `FilesystemAdapter` annotation from `ServeFile::__invoke()`. Keep the truthful disk contract, leave contract-owned `exists()` unsuppressed, and place the same concise WHY plus exact `method.notFound` suppression on `serve()`. Do not change `Storage::fake()`'s accurate local-adapter annotation.

Document the intentionally native-`mixed` data boundaries on `Message::attachData()`, `Message::embedData()`, and `TextMessage::embedData()` with Laravel's `@param resource|string $data`. PHP cannot express `resource` natively; do not widen the public contract to Symfony's tolerated `File`, narrow the native parameters, or add runtime conversion.

Keep the protected provider extension point and explain its retained name truthfully:

```php
/**
 * Register the mailer instance.
 *
 * The method name is retained for compatibility with Laravel's protected extension point.
 */
protected function registerIlluminateMailer(): void
```

### 8. Split-package dependencies

Change `Attachment::attachTo()` to native `object` with the precise supported PHPDoc union:

```php
/**
 * Attach the attachment to a built-in mail type.
 *
 * @param Mailable|MailMessage|Message $mail
 */
public function attachTo(object $mail, array $options = []): mixed
```

Runtime dispatch remains the existing `attach()` / `attachData()` structural API. Type the path strategy parameter as `string` to document the built-in resolver contract, not as an analysis workaround. Preserve the data resolver's existing runtime contract and remove both the method-wide suppression and false `@var Mailable` narrowing. At the repository's PHPStan level the truthful union typechecks without suppression because the data and filename strategy expressions remain `mixed`; do not claim that the three target parameter signatures are identical. Add no target interface, reflection, duplicated per-target dispatch, or suppression absent a newly reproduced error.

Use `Closure|string` for `raw()`, `html()`, and `plain()` callbacks in the concrete Mailer and fake, and for `raw()` in `Contracts\Mail\Mailer`. These are the complete supported callback shapes passed to `send()` and match Laravel; propagate them to facade metadata rather than copying `mixed` into new declarations.

Add one focused `tests/Console/Scheduling/EventTest.php` case for the contract's only external consumer: register `emailOutputTo()`, invoke the after callback with mocked Mailer and Filesystem dependencies, and assert captured output reaches `raw()` with the expected subject and recipients. This closes an existing public-behavior coverage gap; it is not a counterfactual regression for the type correction. The source trace showing `Event::emailOutput()` passes a Closure, together with repo-wide PHPStan, verifies compatibility with `Closure|string`.

In `src/mail/composer.json`:

- remove direct `hypervel/notifications` after the source reference is gone;
- remove hard `hypervel/testing`;
- require `symfony/mime` and `symfony/http-foundation` because shipped source dereferences them;
- suggest `hypervel/http` for UploadedFile attachments;
- suggest `hypervel/filesystem` for storage attachments;
- suggest `hypervel/testing` for ordered Mailable assertions;
- suggest `phpunit/phpunit` for Mailable assertion methods;
- retain the Symfony HTTP client and transport suggestions.

Do not claim the full repository graph is acyclic: Support intentionally aggregates core providers and currently creates broader transitive cycles. Do not redesign that package graph in this Mail correction.

Add `tests/Mail/PackageMetadataTest.php` for direct split/root consistency, provider metadata, required dependencies, optional suggestions, absence of Mail's direct Notifications/Testing requirements, and the existing root replacements. Root Symfony/PHPUnit entries already exist; do not run Composer for unchanged root constraints.

### 9. Facade metadata and current tests

Change `MailManager`'s mixin from the narrow Mailer contract to the concrete `Mailer`, matching Laravel and the manager's actual resolver. Unlike arbitrary Filesystem extensions, Mail extensions create transports and cannot replace the concrete mailer. Then refresh the Mail facade from current Laravel in upstream order while retaining Hypervel manager pooling methods, fake assertions, and accurate contract return types. Include `always*`, optional names on `to/cc/bcc`, `html`, `plain`, `render`, queue methods, transport/view/queue accessors, and macros. Regenerate the Notification facade after narrowing `NotificationFake::assertSentTo()`. Underlying signatures remain authoritative for facade-documenter output; do not add a redundant concrete `@see` route. Pin the concrete forwarded Mail surface in `PackageMetadataTest` so a future narrow mixin cannot silently remove it.

Port or merge, one file at a time:

- current `tests/Mail/AttachmentTest.php`;
- PR/current delayed-queue regressions in `MailableQueuedTest.php`;
- `assertHasNoAttachments()` regressions in `MailMailableTest.php`;
- SES tenant regressions in `MailSesV2TransportTest.php`;
- current `SupportTestingMailFakeTest.php`, preserving Hypervel's `sendNow` and public `assertQueuedTimes` coverage;
- current `SupportTestingNotificationFakeTest.php` for `support-31`;
- the missing `testItEmbedsFilesViaAttachableContractFromData()` in `MailMessageTest.php`.

Read and retain all ten `tests/Integration/Mail/` files and their fixtures: `AttachingFromStorageTest`, `MailableTestCase`, `MailableWithSecuredEncodingTest`, `MailableWithoutSecuredEncodingTest`, `MarkdownParserTest`, `RenderingMailWithLocaleTest`, `SendingMailWithLocaleTest`, `SendingMarkdownMailTest`, `SendingQueuedMailTest`, and `SentMessageMailTest`. `AttachingFromStorageTest` is mandatory revalidation for the storage resolver and the widened `attachTo()` target; the remaining integration files retain rendering, locale, queued delivery, sent-message, secured encoding, and Markdown behavior.

Do not duplicate the already-present data-attachment test, legacy six-config compatibility tests, rejected duplicate address-control-character tests, or behavior already covered more strongly by Hypervel enum/pooling tests.

### 10. Test hygiene

Change the seven raw PHPUnit Mail tests to `Hypervel\Tests\TestCase`. Give test methods `: void`; type helpers/providers with their actual return contracts. Do not type providers `void`.

The seven base-class conversions are:

- `AttachableTest.php`;
- `MailMailableAssertionsTest.php`;
- `MailMailableDataTest.php`;
- `MailMailableHeadersTest.php`;
- `MailMarkdownTest.php`;
- `MailMessageTest.php`;
- `MailableAlternativeSyntaxTest.php`.

Apply the same truthful typing pass to all ten `tests/Integration/Mail/` files: add `: void` to test methods, and type lifecycle hooks, test parameters, helpers, and data providers according to their real contracts. Type `MailableTestCase::defineEnvironment(ApplicationContract $app): void` to match its parent. Retain `MailableTestCase`; its shared environment setup justifies the package base class.

Apply the same rule to every additional test file this work edits. In particular, complete the existing partial typing in `MailMailerTest`, `MailMailableTest`, and `MailableQueuedTest`, including truthful helper return types; do not leave a touched test file internally inconsistent.

Before marking Mail complete, add `: void` to the remaining ten untyped test methods in `MailLogTransportTest`, `MailResendTransportTest`, `MailFailoverTransportTest`, and `MailRoundRobinTransportTest`.

Move `MailMessageTest`'s image into a per-worker `ParallelTesting::tempDir()` created in `setUp()` and removed in `tearDown()`. Remove per-test `unlink()` calls once that directory owns cleanup. Give `MarkdownCoroutineSafetyTest` the same owned-directory lifecycle. Cleanup is exception-safe and does not duplicate framework static resets.

### 11. Public documentation and intentional differences

Keep the README minimal and ordered:

1. package header;
2. existing package badge;
3. `Documentation: https://hypervel.org/docs/mail`;
4. Differences From Laravel;
5. `Ported from: https://github.com/laravel/framework`.

Retain the cloud-storage-helper difference and add one concise SES statement: Hypervel supports Amazon SES through SES v2 only; the `ses` mailer uses `ses-v2`. Add the matching concise source insertion comment and `REMOVED:` test marker. Do not port SES v1.

Update the Boost Mail guide at natural sections only:

- `fromUrl()` accepts HTTP(S); use `fromPath()` for local paths;
- `assertHasNoAttachments()`;
- `X-SES-TENANT-NAME`;
- primary queue methods accept enum identifiers;
- pooled named mailers expose `TransportPoolProxy`; configure through `mail.php`, or use `pool => false` only when stable concrete transport inspection is required.

Do not duplicate the guide in README or document internal construction/state machinery.

Correct the completed HTTP README separately: restore its package badge, link to the canonical Requests guide, retain approved differences, and move `Ported from:` to the final position.

## Rejected concerns

- No duplicate address control-character validator; Symfony Mime owns the active boundary.
- No transport retries, health checks, or poisoned-resource policy without a demonstrated broken-resource lifecycle.
- No array/log history redesign.
- No attachment streaming redesign or Filesystem contract expansion.
- No pool-proxy magic forwarding: a proxy cannot expose one stable concrete transport after releasing a lease.
- No SES v1 or compatibility wrapper.
- No protected provider-method rename.
- No removal of intentional Markdown fallbacks.
- No speculative interception of fake configuration methods or fake delay storage.
- No Support package-graph redesign inside the Mail slice.
- No pre-guard for unreadable storage attachment data: modern Mail targets already reject null at Hypervel's typed boundary and `Message` reaches Symfony's explicit body-type exception. Unlike failed MIME detection, there is no valid fallback result to preserve.
- No notification-specific callback-ordering test, selectable callback-order mode, re-render, or body snapshot; none can add evidence beyond the two discriminating regressions without extra machinery.

## Validation plan

Run each changed/new test file immediately after editing it. Then run:

1. all `tests/Mail/`, `tests/Integration/Mail/`, `tests/Support/SupportTestingMailFakeTest.php`, and `tests/Support/SupportTestingNotificationFakeTest.php` tests;
2. `tests/Support/SupportStrTest.php`, `tests/Support/SupportStringableTest.php`, the focused Validation URL tests, and the new Console scheduling email-output test to revalidate existing consumers;
3. `tests/Integration/Filesystem/ServeFileTest.php`, facade generation and scoped lint for `Hypervel\Support\Facades\Mail` and `Hypervel\Support\Facades\Notification`, and package metadata tests for Mail and HTTP;
4. `composer fix`, which runs PHP CS Fixer, both PHPStan configurations, the full parallel suite, the Testbench package suite, and Testbench dogfood.

Counterfactual assertions must fail against the old implementation: explicit delayed queues and every enum queue alias do not type-error; direct and `Envelope(using: [...])` callbacks observe and replace rendered content; short internal HTTP hosts validate while non-HTTP schemes fail; false MIME does not reach a string boundary; disk resolution occurs once; fake side-effect methods never call real transports/queues; invalid fake queue calls throw while consuming selection; explicit fake queues and selected mailers survive recording; ShouldQueue selection pulls once; SES tenant names, including `"0"`, are exact; the Mail facade exposes the concrete forwarded surface.

Additional validation keeps Mail and Notification facade metadata synchronized and revalidates worker-safe temporary-file ownership.

## Completion records

After implementation and final review:

- add the Mail package ledger entry with every accepted/rejected concern, upstream PR inventory, performance/API/coroutine conclusion, tests, and review result;
- mark Mail complete in the core checklist;
- add completed-package amendments for `support-28`, `support-29`, `support-30`, `support-31`, `contracts-10`, `contracts-11`, `http-27`, and `filesystem-14`, including Console's use of the narrowed Mailer callback contract and Support's remaining EventFake test-parity todo;
- record Validation's completed consumer revalidation under `support-29` in the new Mail work unit; Validation has no completed package-ledger entry to amend and remains pending its later full audit;
- add dependency-index rows only for the shared lower-level findings: `mail-17` affects Mail and Support, `support-28` affects Support and Mail, `support-29` affects Support, Mail, and Validation, `contracts-10` affects Contracts and Mail, `contracts-11` affects Contracts, Mail, and Console, and `filesystem-14` affects Filesystem and Mail; `support-30`, `support-31`, and the HTTP README-only correction change no assumption consumed by another package;
- update Support's ledger summary for MailFake/facade and `Str::isUrl()`, Contracts for the queue and callback types, Console for its callback consumer revalidation, HTTP for its README correction, and Filesystem for the truthful dynamic adapter-method boundary;
- update the routing index with every newly assigned finding and verify IDs do not collide.

## Final self-review

Trace every changed caller/callee and recheck current Laravel source order. Confirm:

- no supported Laravel public API or protected extension point was removed or narrowed; the broad native `mixed` callbacks are corrected to Laravel's documented `Closure|string` boundary, so unsupported values do not count as compatibility;
- callback ordering matches current Laravel across direct sends, mailables, and notifications, including the accepted view-state and MIME attachment-order consequences;
- SES v2-only is the sole new documented intentional difference;
- fake state is operation-local and no selected mailer survives a completed or failing side-effect call;
- no worker/coroutine state, lock, registry, retry, cache, network call, or hot-path resolution was added;
- storage resolution and production dependency load are reduced;
- no runtime-false filesystem concrete annotations or broad dynamic-method suppressions remain;
- no dead helper, stale suppression, misleading comment, duplicate documentation, or unowned test file remains.
