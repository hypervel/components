# Complete Notifications correctness, Slack parity, and reentrant failure ownership

## Objective

Bring Notifications to current Laravel framework and Slack notification-channel behavior, correct the verified Slack and nested-failure defects, preserve Hypervel's worker/coroutine ownership, and finish the package audit without speculative machinery or stale code.

The implementation must retain supported Laravel APIs, named arguments, protected extension points, immutable-date behavior, strict types, direct Slack routing, and stateless worker-lifetime channel caching. Hypervel-specific behavior may differ only where the Swoole runtime requires it or where copying upstream would preserve a verified defect.

## Evidence baseline

- Hypervel baseline: `990475213640b743ce11a52e1904c268f0680d1d` on `audit/notifications-correctness-parity`.
- Laravel framework reference: local `examples/laravel/framework`, branch `13.x`, commit `2c410561c21452de2f164caea64ab0fcac692a5d`.
- Laravel Slack reference: local `examples/laravel/slack-notification-channel`, branch `3.x`, commit `f5690359278aaebf5a0e5b07659caea95b6ded40`.
- Laravel docs reference: local `examples/laravel/docs`, branch `13.x`, commit `68f903aca708d7c9070f73127e64468132b1266b`.
- Laravel Horizon reference: local `examples/laravel/horizon`, branch `5.x`, commit `2ebe3cb25ab6461b53a4e3ef42e167edeafe7932`.
- The focused Notifications, Notifications integration, and queued missing-model baseline is green.
- Historical pull requests identify the complete originating file sets; implementation comes from the current branches above.

| Upstream change | Originating files checked | Current result |
|---|---|---|
| Framework [#60268](https://github.com/laravel/framework/pull/60268) | `MailMessage.php`, `NotificationMailMessageTest.php` | Port storage attachment APIs and current tests. |
| Framework [#59468](https://github.com/laravel/framework/pull/59468) | `ReadsClassAttributes.php`, `NotificationSenderTest.php` | Shared source is already correct; port Notifications consumer regressions only. |
| Framework [#57718](https://github.com/laravel/framework/pull/57718) | `HasDatabaseNotifications.php`, Eloquent type fixture | Port relationship generics; preserve Hypervel native return types. |
| Slack [#100](https://github.com/laravel/slack-notification-channel/pull/100) | modern `SlackMessage.php`, feature test | Port the public builder URL accessor. |
| Slack [#103](https://github.com/laravel/slack-notification-channel/pull/103) | actions, button, select classes/trait/contract and tests | Port selects; correct discarded seeds, invalid generated IDs, and empty option values. |
| Slack [#106](https://github.com/laravel/slack-notification-channel/pull/106) | webhook channel, legacy message | Accept modern and legacy messages; add missing direct coverage. |
| Slack [#108](https://github.com/laravel/slack-notification-channel/pull/108) | actions block, users select | Port users-select behavior and tests. |
| Slack [#112](https://github.com/laravel/slack-notification-channel/pull/112) | plain-text object and test | Port UTF-8-safe byte truncation. |

## Anti-overengineering rules

The following wording is retained verbatim from the core audit plan.

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

A correctness guard on a cold failure path has a different cost from a new lock or resolver on every request. State the difference explicitly.

Any proposed change with a measured or source-proven hot-path regression requires explicit owner approval before implementation, even when it fixes a defect. Present the expected frequency and magnitude, the evidence, and the viable alternatives. Do not hide an unavoidable tradeoff inside a general correctness claim.

Performance improvements must provide a meaningful practical benefit after accounting for code complexity and divergence from upstream. Measure representative behavior where practical. Always surface an evidence-backed opportunity to the owner, but do not implement it without approval; a micro-optimization within measurement noise is neither a reason to diverge nor an actionable finding.

## Architecture and retained boundaries

- `ChannelManager` remains a worker-lifetime singleton. Its built-in cached drivers are stateless; custom resource-owning channels own their resource lifecycle.
- `deliverVia()` and `locale()` remain coroutine-local. A fresh `NotificationSender` is constructed for each public `send()` / `sendNow()` call.
- Optional `NotificationSending`, `NotificationSent`, and `NotificationFailed` construction stays guarded by `hasListeners()`.
- Queue connection, queue, delay, message-group, deduplicator, middleware, and cached attribute behavior remain at their existing Queue-owned boundaries.
- Mail Markdown themes remain per render. Keep the `'default'` fallback because application replacement of the second-level `mail.markdown` array may intentionally omit `theme`.
- Both legacy Slack attachments and modern Block Kit messages remain supported. Horizon intentionally chooses between them by route type because webhook delivery preserves its legacy red attachment and configured channel.
- Slack delivery remains direct and unpooled; each send performs one HTTP request. No request-scoped channel registry or pooled notification-manager layer is added.
- The failure-attempt marker is invocation state in `CoroutineContext`; it is absent outside an attempt and holds only a boolean while an attempt is active.

## Findings and final decisions

Existing `notifications-07` (Factory accepts single notifiables) and `notifications-08` (database notification read-state projection) are revalidated, not reassigned.

| ID | Category | Severity | Confidence | Final decision |
|---|---|---:|---:|---|
| `notifications-09` | Laravel API parity defect | Minor | High | Add `MailMessage` storage attachment methods and documentation. |
| `notifications-10` | Slack parity and default-ID/value defects | Major | High | Port selects; preserve direct construction; fix discarded seeds, overlong/empty automatic IDs, and empty normalized option values in their shared owners. |
| `notifications-11` | Encoding defect | Major | High | Use `mb_strcut()` only at the over-limit boundary. |
| `notifications-12` | Slack transport defect | Major | High | Accept modern and legacy Slack messages through webhooks; retain and directly test both Horizon representations. |
| `notifications-13` | Slack API parity | Minor | High | Add the Block Kit Builder URL accessor and make `dd()` delegate to it. |
| `notifications-14` | Coroutine event-ownership defect | Major | High | Save and restore one nullable coroutine-local boolean across nested channel attempts. |
| `notifications-15` | Regression coverage gap | Minor | High | Port current queue precedence and non-null database read-time tests; no source change. |
| `notifications-16` | Coroutine-isolation coverage gap | Minor | High | Add deterministic concurrent manager state tests; no source change. |
| `notifications-17` | Construction and hot-path hygiene | Minor | High | Remove the redundant same-class manager binding and use strict queue-interface membership; retain aliases and theme fallback. |
| `notifications-18` | Split metadata and provenance | Minor | High | Remove unused Object Pool and Filesystem requirements, add `ext-mbstring`, and document both tracked upstreams. |
| `notifications-19` | Type completeness | Minor | High | Add only evidence-backed native types and relationship generics. |
| `notifications-20` | Test harness and source mapping | Minor | High | Use framework test bases, restore direct class-to-test mapping, and make every test discoverable and typed. |

## Implementation

### 1. Mail storage attachments

Add the current methods after `attachData()` in `Messages\MailMessage`, preserving upstream order and named arguments:

```php
public function attachFromStorage(
    string $path,
    ?string $name = null,
    array $options = []
): static {
    return $this->attachFromStorageDisk(null, $path, $name, $options);
}

public function attachFromStorageDisk(
    ?string $disk,
    string $path,
    ?string $name = null,
    array $options = []
): static {
    $attachment = Attachment::fromStorageDisk($disk, $path);

    if ($name !== null) {
        $attachment->as($name);
    }

    if (isset($options['mime'])) {
        $attachment->withMime($options['mime']);
    }

    return $this->attach($attachment);
}
```

Port default/named disk, basename fallback, display-name, and MIME regressions into `NotificationMailMessageTest`. Root the default disk at `ParallelTesting::tempDir()` and the named disk in its own child root, clearing the scratch directory before creating either tree. Install the test container with `Container::setInstance()`, then bind its `config`, `filesystem` manager, and `FilesystemFactory` alias before invoking either storage method; the attachment resolves the disk immediately. Use framework filesystem cleanup. Replace the false guide limitation with concise `attachFromStorage()` / `attachFromStorageDisk()` examples on `MailMessage`; do not route users through a temporary local file or require a Mailable.

### 2. Block Kit selects and safe automatic IDs

Port the current upstream files:

- `Slack/Contracts/AccessoryContract.php`;
- `Slack/BlockKit/Elements/Traits/GeneratesDefaultIds.php`;
- `Slack/BlockKit/Elements/Selects/{SelectElement,SelectOption,StaticSelectElement,UsersSelectElement}.php`.

Add `ActionsBlock::staticSelect()` / `usersSelect()` in upstream order. Both select constructors accept `?string $text = null`: ActionsBlock-supplied text gives deterministic IDs, while direct parameterless construction retains Laravel's random fallback. `ButtonElement` adopts the same trait without changing ordinary IDs.

Select fluent mutators return `static`, matching the package's existing typed Button API so chains retain the concrete select subtype.

The shared helper owns the current Button defect and the new select behavior:

```php
private function resolveDefaultId(string $prefix = '', ?string $text = null): string
{
    $slug = $text === null ? '' : Str::lower(Str::slug(substr($text, 0, 248)));

    // Str::slug may expand or erase the bounded seed, so substitute an empty
    // result and cap the final Slack action ID separately.
    if ($slug === '') {
        $slug = uniqid();
    }

    return substr($prefix . $slug, 0, 255);
}
```

The inner cap preserves current valid IDs and bounds slug work; only the outer cap enforces Slack's 255-byte output limit. `uniqid()` is the existing null-seed fallback and is also used when valid display text cannot form a usable ASCII slug. Do not add a registry, collision tracker, hash policy, entropy flag, or test of PHP's generator.

`SelectOption` accepts the proven `Stringable|string|int|float|bool` values and normalizes once before `Str::lower()`. Narrow literal-regex output locally if PHPStan requires it. Reject an empty normalized value at construction with `InvalidArgumentException`; distinct options must not emit the same empty Slack value. Do not change the regex or add per-type rules.

Counterfactual coverage must prove:

- an expanding Button seed no longer exceeds 255;
- both select prefixes fit at the long-ASCII boundary;
- two non-transliterable labels receive distinct nonempty IDs;
- ordinary Button/select IDs remain byte-identical;
- explicit overlong `id()` still throws;
- valid scalar option values normalize, while non-Latin/punctuation-only/empty values that normalize to empty fail clearly;
- static/users select placeholders, focus, initial values/options, explicit IDs, deterministic seeded IDs, and unknown-option rejection work.

### 3. UTF-8-safe Slack text

Keep the existing byte-based protocol checks, but replace only unsafe over-limit slicing:

```php
if (strlen($text) > $maxLength) {
    $text = mb_strcut($text, 0, $maxLength - 3, 'UTF-8') . '...';
}
```

Test valid UTF-8 at the boundary, ellipsis/max length, and unchanged ASCII. Do not convert all Slack limits to character counts: upstream's `strlen()` may under-fill multibyte fields but cannot exceed Slack's limit, so there is no delivery failure to justify a public behavioral divergence.

### 4. Webhook transport and Horizon representations

Widen only `SlackWebhookChannel::buildJsonPayload()` to the modern/legacy message union:

```php
public function buildJsonPayload(
    SlackMessage|LegacySlackMessage $message
): array {
    if ($message instanceof SlackMessage) {
        return ['json' => $message->toArray()];
    }

    // Existing complete legacy attachment payload remains unchanged.
}
```

Import the modern class as `SlackMessage`, alias the existing message class as `LegacySlackMessage`, and retype the legacy-only `attachments()` helper to `LegacySlackMessage`. Its body and the `fields()` helper remain unchanged.

Port/create direct webhook and router tests for modern and legacy payloads, string URLs, PSR URI routes, Web API routes, and `false` short-circuiting. Do not convert modern messages to legacy attachments or add a second router/channel.

Keep `Horizon\Notifications\LongWaitDetected::toSlack()` unchanged. Add direct tests for both intentional representations:

- HTTP webhook route: legacy message retains `danger` attachment color and `Horizon::$slackChannel`;
- non-HTTP/Web API route: modern Block Kit message retains its header/section payload.

Place these at `tests/Horizon/Notifications/LongWaitDetectedTest.php`, mirroring the source namespace. Bind a local `ConfigRepository` as `config` so `horizon.name` is available; do not add manual Horizon teardown because `AfterEachTestSubscriber` already calls `Horizon::flushState()`.

### 5. Block Kit Builder URL

Add `Slack\SlackMessage::toBlockKitBuilderUrl(): string` in current upstream order, extracting Hypervel's existing no-flag encoding:

```php
return 'https://app.slack.com/block-kit-builder#' . rawurlencode(
    json_encode(Arr::except($this->toArray(), ['username', 'text', 'channel']))
);
```

Do not copy upstream's stray `true` JSON flag, which would add `JSON_HEX_TAG` and change the established URL payload. Make `dd(bool $raw = false): never` dump either the raw payload or the accessor result. Add direct exact-URL coverage and one concise guide sentence explaining that applications may retrieve the URL without terminating.

### 6. Reentrant failure-event ownership

Replace the reset-only flag with save/restore semantics around exactly `driver()->send()`:

```php
$previousFailureState = CoroutineContext::get(self::FAILED_EVENT_DISPATCHED_CONTEXT_KEY);
CoroutineContext::set(self::FAILED_EVENT_DISPATCHED_CONTEXT_KEY, false);

try {
    $response = $this->manager->driver($channel)->send($notifiable, $notification);
} catch (Throwable $exception) {
    if (CoroutineContext::get(self::FAILED_EVENT_DISPATCHED_CONTEXT_KEY) !== true) {
        // Existing exception normalization and guarded failure dispatch.
    }

    throw $exception;
} finally {
    if ($previousFailureState === null) {
        CoroutineContext::forget(self::FAILED_EVENT_DISPATCHED_CONTEXT_KEY);
    } else {
        CoroutineContext::set(
            self::FAILED_EVENT_DISPATCHED_CONTEXT_KEY,
            $previousFailureState,
        );
    }
}
```

The constant docblock must state: boolean while an attempt is active, absent otherwise, and coroutine-local because a per-instance listener would leak into the process-global dispatcher. The boot listener marks only an active attempt:

```php
if (CoroutineContext::get(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY) !== null) {
    CoroutineContext::set(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY, true);
}
```

Move the two mocked listener simulations out of `NotificationChannelManagerTest` and build `tests/Integration/Notifications/NotificationFailedEventTest.php` on Testbench so the real provider listener is exercised. Cover channel-owned failure plus nested success, nested failure, sequential attempts, success/exception cleanup, an external failure event with no active attempt, and sibling-coroutine isolation.

This adds two small coroutine-local operations to a successful notification-channel attempt, plus restore/forget; it adds no lock, I/O, retained allocation, reflection, container resolution, or request-wide work. Transport and serialization dominate even at high notification volume.

### 7. Manager cleanup and bounded types

- Remove only `singleton(ChannelManager::class, ...)`; Hypervel auto-singletons the unbound concrete, and Dispatcher/Factory aliases must resolve the same instance.
- Keep both aliases and add a real-container identity regression.
- Make the `ShouldQueue::class` `in_array()` check strict.
- Keep `markdownTheme()`'s `'default'` fallback and per-render lookup.
- Add `AnonymousNotifiable::getKey(): mixed` with an explicit `return null`; the native type otherwise turns the anonymous identity used by notification fakes and broadcast channel naming into a runtime `TypeError`.
- Add `Builder` native returns and `Builder<static>` generics to `DatabaseNotification::scopeRead()` / `scopeUnread()`. Each scope mutates the supplied Eloquent builder, then returns that builder explicitly so the native contract remains accurate across PHPStan's query-builder mixin boundary.
- Add `MorphMany<DatabaseNotification, $this>` generics to all three `HasDatabaseNotifications` relationships and `MorphTo<Model, $this>` to `DatabaseNotification::notifiable()`. Pin all four concrete relationship types in the Eloquent type fixture. Remove only the return-type suppression proven unnecessary; keep required dynamic-scope suppressions.
- Add `SendQueuedNotifications::__clone(): void`.

No package-wide style rewrite, scope rename, wrapper, or contract change belongs here.

### 8. Regression parity, coroutine coverage, tests, metadata, and docs

Port queue precedence tests into `NotificationSenderTest`: runtime `onQueue()`, queue attribute fallback, and constructor property precedence. Port a frozen non-null `initialDatabaseReadAtValue()` regression into `NotificationDatabaseChannelTest`. Add database integration coverage proving the public read and unread scopes select only their matching rows through `DatabaseNotification::query()`. The builder form is required because the public instance predicates occupy the same `read` and `unread` names, preventing static scope dispatch; retain both Laravel APIs. These are source revalidations except for the scope coverage accompanying their native return completion.

Create `tests/Notifications/CoroutineIsolationTest.php`. Concurrent coroutines must interleave after setting different `deliverVia()` / `locale()` values, then observe only their own values; a fresh context observes the worker default.

Restore Slack test mapping with moves/copies rather than rewrites:

- `tests/Notifications/Slack/TestCase.php` is abstract and contains only the shared Guzzle/config fixtures and helpers used by message/Web API tests; it extends `Hypervel\Tests\TestCase`.
- shared Slack notifiable/notification helpers move to `tests/Notifications/Slack/Fixtures/` with `SlackRoute|string|null` route types;
- move message-building coverage to `tests/Notifications/Slack/SlackMessageTest.php`;
- move route/auth coverage to `tests/Notifications/Slack/SlackWebApiChannelTest.php`; rename the existing empty-route method to describe its actual no-route input, then add `SlackRoute::make()` and token-only `SlackRoute::make(null, $token)` cases;
- add `NotificationSlackChannelTest`, `SlackNotificationRouterChannelTest`, `ImageElementTest`, `SelectOptionTest`, `StaticSelectElementTest`, and `UsersSelectElementTest` in the established package/upstream layout;
- rename `it_can_reply_as_thread()` to a discoverable `test...` method;
- move the shared setup into the abstract base as `protected function setUp(): void`, call `parent::setUp()`, and remove the property-nulling Slack `tearDown()`; the framework base owns Mockery cleanup.

The no-route and `SlackRoute::make()` inputs must both assert the same configured channel/token result. The token-only route is the discriminating case: token comes from the route while channel comes from the notification.

Convert all 17 raw PHPUnit bases under `tests/Notifications` to `Hypervel\Tests\TestCase` and add missing `: void` test returns. Do not add `RunTestsInCoroutine` to individual tests or create another Notifications-wide base.

Add the missing `: void` returns to the existing tests under `tests/Integration/Notifications`; this bounded directory is part of the active verification surface.

Make process-global integration fixtures class-owned: initialize and remove the anonymous notification route accumulator around each test, and clear the Translation missing-key probes before and after each test. This removes verified order dependence without adding a shared cleanup mechanism.

Update split metadata:

- remove unused direct `hypervel/object-pool` and `hypervel/filesystem` requirements;
- retain directly used `symfony/console`, `hypervel/conditionable`, and `hypervel/macroable`;
- add direct `ext-mbstring: "*"`; root already requires the extension, so do not run `composer require` or change the root manifest/lock;
- add `PackageMetadataTest` for split/root consistency, provider discovery, retained Console/Conditionable/Macroable, removed dependencies, and mbstring;
- keep the README minimal: documentation link, then both tracked upstream links; no internal pooling note or duplicate user guide.

Update `src/boost/docs/notifications.md` only for storage attachments, select interactivity, and the retrievable Block Kit Builder URL. Preserve its existing Laravel-style structure and avoid a second reference manual.

## Rejected concerns that must remain recorded

- Do not cache a worker-lifetime `NotificationSender`: current construction is small, preserves local invocation state, and avoids dynamic-locale machinery.
- Do not scope or rebuild `ChannelManager`; its cached built-in drivers are stateless and its mutable request state is already coroutine-local.
- Do not restore manager-level notification pooling. Custom resource-owning channels own any necessary pooling.
- Do not remove the Markdown theme fallback or mutate the shared renderer.
- Do not remove Horizon's route-dependent legacy representation.
- Do not add a Laravel `driver()` override; Hypervel's shared Manager already owns the enum-capable contract.
- Do not change optional event guards.
- Do not narrow `SectionBlock::accessory()` beyond current upstream.
- Do not change Slack's normalization regex, add an ID registry, or treat upstream parity as proof that invalid generated IDs are acceptable.
- Retain byte-based Slack length checks: their possible under-fill is not a delivery defect.
- `ReadsQueueAttributes` is an intentional Queue-owned domain alias over `ReadsClassAttributes`, not dead indirection.

## Verification

Work one file at a time. After each changed/new test file, run it immediately. Then run:

```shell
./vendor/bin/phpunit --no-progress tests/Notifications
./vendor/bin/phpunit --no-progress tests/Integration/Notifications
./vendor/bin/phpunit --no-progress tests/Integration/Queue/DeleteNotificationWhenMissingModelTest.php
./vendor/bin/phpunit --no-progress tests/Horizon/Notifications/LongWaitDetectedTest.php
composer fix
```

Before review, trace every changed caller/callee and verify:

- no Slack/API method or named argument was lost;
- automatic IDs are nonempty and at most 255 bytes without changing ordinary IDs;
- nested attempts restore the exact prior boolean and leave no context key after the outer attempt;
- unrelated external failure events do not create state;
- coroutine manager state cannot cross siblings;
- modern and legacy Slack representations preserve their route-specific payloads;
- all new source/test files follow upstream mapping and no test relies on load order;
- removed dependencies have no direct source, test, or executable package-boundary consumer;
- no obsolete comment, mock simulation, false documentation, suppression, or superseded helper remains;
- `composer fix` is green, followed by a fresh full-diff self-review and independent code-review sign-off.

## Completion records

When implementation begins, set the core audit routing index to:

- **Active package or work unit:** Notifications correctness, Slack parity, and reentrant failure ownership.
- **Ledger entries required for the active work:** `Harden framework contracts and request-scoped state`; `Complete Macroable callable and test-state handling`; `Isolate logging state and capture queued payloads deterministically`; `Correct cookie retrieval types and selective-encryption guidance`; `Normalize framework enum identifiers at string boundaries`; `Complete Queue pooling, payload durability, and current Laravel parity`; `Complete Horizon cluster, process, publication, and current Laravel parity`; `Harden Eloquent identity and partial-projection safety`.
- **Pending revalidation carried into the active work:** `notifications-07`, `notifications-08`, `queue-41`, `support-02`, and `macroable-03`; Horizon representation revalidation is owned by new finding `notifications-12`.

After implementation and review:

1. Add one compact Notifications entry to `2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md` with findings `notifications-09` through `notifications-20`, the two rejected concerns called out by consensus, regressions, performance, and final assessment.
2. Record the Laravel-facing result explicitly: current supported APIs, named arguments, configuration, and direct construction remain compatible; the changes are additive except for correcting verified upstream automatic-ID defects, and no public API is removed.
3. Mark Notifications revalidation complete on the existing `notifications-07`, `notifications-08`, `queue-41`, and `support-02` dependency-index rows, and point `notifications-07` at its Contracts-owning ledger entry. Add the missing `macroable-03` row with Cookie, Log, and Notifications complete and only JWT still pending.
4. Amend every prior pending claim: the Contracts and Queue entries for `notifications-07`, the Eloquent identity entry for `notifications-08` / `queue-41`, and the Macroable entry for `macroable-03`. Retain the shared `notifications-07` ID in both affected work units as the ledger rules require, but normalize their finding-row wording and completion state. Add evidence-only notes to the completed Cookie and Log entries that this work verified their split manifests already carry the direct Macroable requirement; do not attribute that verification to those earlier audits.
5. Add the cross-package `notifications-12` row and amend the completed Horizon ledger entry with its representation revalidation.
6. Mark the Notifications package checkbox complete only after gates, self-review, code-review sign-off, owner summary/approval, and the bookkeeping commit.
7. Recompute the `src/*` package checklist and ensure the package/index sets still match.
8. Clear all three routing-index lines back to `None` after the completion records land.
