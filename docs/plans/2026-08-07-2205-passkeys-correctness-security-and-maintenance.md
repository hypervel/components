# Passkeys correctness, security, and maintenance

**Status:** Complete; implementation, verification, self-review, and independent code review signed off.

## Objective

Complete the Passkeys audit by correcting verified registration, verification, persistence,
configuration, pruning, metadata, documentation, and catalogue-maintenance defects while preserving
Laravel-facing APIs and Hypervel's polymorphic owners, current-guard authority, request-aware
multi-tenant callbacks, worker-static cleanup, and extension points.

The design adds no request state, context slot, lock, cache, retry, registry, compatibility wrapper,
digest column, second lookup, or new application configuration. The only successful-runtime work is
one registration-only fixed-size comparison and one empty-secret comparison. Verification adds one
O(1) string-length read before its existing indexed lookup.

## Evidence baseline

- Hypervel branch baseline: `0.4` at `b75e579c0175bec17555b8197ecd216574f76e9b`.
- Current Laravel Passkeys reference: `examples/laravel/passkeys-server` at
  `4f81dfd16512fe81d73f5df6617a300b1c761602`.
- Upstream PR `#12` / commit `d726de6` introduced the public action extension points across its
  README, four action classes, and registration-options tests. Commit `ef7c358` added the broader
  customization guide. Their changed files were used for discovery; this plan uses the current
  upstream files as the implementation/documentation reference.
- Upstream commit `6725c6f` introduced AAGUID synchronization across its workflow, script,
  catalogue, and support class; `590594a` changed the resource to PHP. Current versions of all four
  surfaces were checked before adapting the workflow and script.
- Installed WebAuthn reference: `web-auth/webauthn-lib` 5.3.5.
- `CheckCredentialId` accepts 1,023 raw bytes in the creation ceremony. Unpadded Base64URL needs at
  most 1,364 characters for that value. The request ceremony has no credential-ID length step.
- Live MySQL 9.5, MariaDB 10.11, PostgreSQL 17, and SQLite probes established that PostgreSQL
  `bytea` hydrates through PDO as a stream resource, while a 1,364-character string with MySQL-only
  `character set binary` remains a PHP string and compares case-sensitively on every driver.
- `MySqlGrammar` alone declares the `Charset` modifier; `MariaDbGrammar` inherits it. PostgreSQL and
  SQLite ignore the modifier and retain their case-sensitive text behavior.
- The checked-in AAGUID catalogue matches current upstream after Hypervel's strict-types header is
  accounted for. The defect is publication/maintenance, not stale current data.
- `symfony/http-foundation` was added after the findings snapshot. Four direct split-package
  requirements remain missing.
- Current upstream shares the credential-size, registration-owner, expected-rejection, and blank
  secret defects. Upstream parity does not justify retaining them.
- The full source, tests, current upstream, provider/config integration, dependency graph, database
  grammar behavior, and Fortify bridge were traced before this plan.

## Anti-overengineering rules

The following wording is retained verbatim from the core audit plan. Its principle numbering is
also retained; principles 1–6 remain in the core operating plan. In principle 9, “later in this
plan” refers to that plan's **Established remediation vocabulary** section.

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

## Retained architecture and API boundaries

- `Passkeys::guard()` and `guardName()` continue to use Hypervel's current request guard. Standalone
  `passkeys.guard` and Fortify's guard middleware remain the route-level selection mechanisms.
- Passkey owners remain polymorphic `PasskeyUser` models; no global user model or owner registry is
  introduced.
- RP ID, allowed origins, and redirect resolvers remain boot-time worker-static callbacks. Dynamic
  RP/origin values still use the current `RequestContext`; static ceremony managers remain cached
  only when origins are not request-aware.
- `Passkeys::flushState()` and `WebAuthn::flushState()` remain the complete worker-static reset
  boundaries. This work adds no new static state.
- Public actions, response contracts, custom model selection, route suppression, events, and the
  ceremony factory callback retain their existing signatures and binding behavior.
- Fortify's nested Passkeys bridge keeps its per-key fallbacks because `fortify.passkeys` is a
  replaceable nested array. Only Passkeys-owned required top-level fallbacks are removed.
- Passkey credential IDs remain unpadded Base64URL strings in PHP, JSON, Eloquent attributes,
  queries, and custom route keys.
- Passkeys continues to publish, rather than automatically load, its migration. The repository is
  pre-release and the final source documents current design only; no compatibility migration or
  historical upgrade prose is added.

## Findings and final decisions

| ID | Category | Severity | Final decision |
|---|---|---:|---|
| `passkeys-01` | Native file publication | Minor | Publish AAGUID data through a checked same-directory temporary file and atomic rename. |
| `passkeys-02` | Split-package metadata | Minor | Add the four still-missing direct runtime requirements and a root-constraint parity test. |
| `passkeys-03` | Public documentation | Minor | Document the current supported customization surface in the canonical Fortify guide. |
| `passkeys-04` | Configuration ownership | Minor | Remove duplicate fallbacks only from required Passkeys-owned top-level reads. |
| `passkeys-05` | Registration identity binding | Major | Reject attaching a validated credential record whose user handle differs from its owner. |
| `passkeys-06` | Credential-ID persistence | Major | Store up to 1,364 case-sensitive Base64URL characters with one portable unique text lookup. |
| `passkeys-07` | WebAuthn error boundary | Major | Translate library-defined ceremony rejections, including counter replay, without hiding infrastructure errors. |
| `passkeys-08` | Security configuration | Minor | Reject an explicitly empty user-handle secret. |
| `passkeys-09` | Public model typing | Minor | Make the orphanable owner property nullable in PHPDoc. |
| `passkeys-10` | AAGUID maintenance | Minor improvement | Add a weekly/manual focused pull-request workflow after publication is made atomic. |
| `passkeys-11` | Worker-state regression | Minor | Pin login-authorization callback cleanup through public behavior. |
| `passkeys-12` | Destructive orphan pruning | Major | Warn and retain ambiguous/unloadable owner types instead of bulk deleting them. |
| `passkeys-13` | Assertion lookup boundary | Minor | Reject raw credential IDs over 1,023 bytes before the assertion lookup and prove no query runs. |

The owner approved `passkeys-10` with this plan. No finding changes a Laravel public signature or
configuration structure. The corrected rejection paths reject inputs that were already invalid or
unsafe; the pruning behavior is a Hypervel-only command correction.

## Implementation

### 1. Publish AAGUID data atomically (`passkeys-01`)

Restructure `src/passkeys/scripts/sync-aaguids.php` so including it defines its focused functions
without executing the network operation. Keep download, validation, rendering, and publication in
the script; do not introduce a framework service.

The publication boundary is:

```php
function publishAaguids(string $destination, string $contents): void
{
    $temporary = @tempnam(dirname($destination), '.aaguids-');

    if ($temporary === false) {
        throw new RuntimeException('Unable to create a temporary AAGUID catalogue.');
    }

    try {
        if (@file_put_contents($temporary, $contents) !== strlen($contents)) {
            throw new RuntimeException('Unable to write the complete AAGUID catalogue.');
        }

        // tempnam() creates the file with 0600; publish it with the repository's normal file mode.
        if (! @chmod($temporary, 0644)) {
            throw new RuntimeException('Unable to set permissions on the AAGUID catalogue.');
        }

        if (! @rename($temporary, $destination)) {
            throw new RuntimeException('Unable to publish the AAGUID catalogue.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}
```

Use a script-execution guard so `tests/Passkeys/AaguidSyncScriptTest.php` can load the functions.
Native-warning suppression is limited to operations whose exact result is immediately checked and
converted to `RuntimeException`. A thrown exception naturally makes the CLI script exit nonzero;
print success only after publication.

The focused test uses `ParallelTesting::tempDir()`. Publish twice with different contents and assert
that each publication is byte-exact and mode `0644`. For deterministic failure coverage, use an
existing nonempty directory as the destination: native `rename()` then returns `false`, the method
throws, and the `finally` block must remove the same-directory temporary file. This works under
CI's root user and avoids unreliable permission-denial tests. Retain the execution guard because
these counterfactual publication assertions exercise the production function directly. Do not add
injected filesystem callables merely to simulate a short write; the exact byte-count branch remains
source-verifiable.

### 2. Complete split-package metadata (`passkeys-02`)

Add these direct requirements to `src/passkeys/composer.json`:

```json
"ext-hash": "*",
"hypervel/context": "^0.4",
"nesbot/carbon": "^3.13.1",
"symfony/http-kernel": "^8.1"
```

Keep `symfony/http-foundation`, which is already present, and do not remove semantic package
dependencies merely because another package currently installs them transitively.

Add `tests/Passkeys/PackageMetadataTest.php` following the established package test shape. List
every direct runtime requirement explicitly and assert that each has a nonempty string constraint.
Assert every direct extension and non-Hypervel package constraint matches root. Keep PHP out of
that parity loop because the split package deliberately uses `^8.4` while root uses `>=8.4`. The
monorepo itself replaces `hypervel/context` with `self.version` rather than requiring it, so assert
the split constraint is `^0.4` and the root replacement remains present. Do not build an import
scanner.

### 3. Remove duplicate Passkeys-owned defaults (`passkeys-04`)

In `Passkeys`, remove only fallback arguments whose defaults are guaranteed by top-level package
config merging:

```php
$origins = self::config()->array('passkeys.allowed_origins');
$timeout = self::config()->integer('passkeys.timeout');

return self::config()->string('passkeys.redirect');
```

Retain origin callback-result filtering and empty-list rejection, positive timeout validation, and
redirect callback validation. The `is_array()` origin guard remains necessary for untyped userland
resolver output.

In `src/passkeys/routes/routes.php` use:

```php
$groupMiddleware = config()->array('passkeys.middleware');
$managementMiddleware = array_values(array_filter(
    config()->array('passkeys.management_middleware'),
));
```

Keep nullable `guard` and `throttle` behavior unchanged. Do not alter the load-bearing nested
fallbacks in `FortifyServiceProvider::configurePasskeys()`. Existing Passkeys and Fortify config,
route, cached-config, and feature tests must remain green.

### 4. Bind registration records to their current owner (`passkeys-05`)

At the start of public `StorePasskey::createPasskey()`, before encoding or inserting, enforce the
attachment invariant:

```php
if (! hash_equals($user->getPasskeyUserHandle(), $source->userHandle)) {
    throw InvalidPasskeyException::make(
        'Passkey registration options no longer match this account. Please try again.',
    );
}
```

The library copies the handle from stored registration options. The action therefore prevents an
options object generated for owner A from being attached through owner B's polymorphic relation.
The method is the lowest shared boundary for built-in controllers and direct/custom callers.

Extend `StorePasskeyTest` for matching and mismatched handles through direct `createPasskey()` use,
including the distinct mismatch message and zero rows for either owner, while retaining the
existing duplicate-insert contract and message. In `PasskeyRegistrationTest`, pin the reachable
premise separately: generate and store registration options as owner A, log out and log in as owner
B through the same real session guard, and assert the serialized owner-A options remain in the
session.

Do not build a full ceremony-level HTTP regression. The suite cannot create an attestation response
that survives the real library validator; doing so would require new CBOR, authenticator-data, COSE
key, and flag fixture machinery merely to reach the action boundary already tested directly. Do not
add ceremony/session registries, owner locks, or a duplicated controller check.

### 5. Store protocol-sized credential IDs exactly (`passkeys-06`)

Change the source migration to:

```php
// CTAP2 permits 1,023 raw bytes, requiring 1,364 unpadded Base64URL characters.
// The binary charset keeps MySQL-family uniqueness case-sensitive like PostgreSQL and SQLite.
$table->string('credential_id', 1364)->charset('binary')->unique();
```

`Charset` reaches only MySQL/MariaDB. PostgreSQL and SQLite compile an ordinary string column and
retain case-sensitive equality. Do not use `binary()` because PostgreSQL `bytea` hydrates as a
resource. Do not add a hash column, generated column, mutator, cast, driver branch, second lookup,
or exact-match follow-up.

Create one shared database integration base at
`tests/Integration/Passkeys/Database/PasskeyCredentialIdTestCase.php` and four minimal driver
wrappers under `MySql`, `MariaDb`, `Postgres`, and `Sqlite`, each using the matching
`#[RequiresDatabase]` value. The existing database workflow discovers this exact layout.

The shared base extends `Hypervel\Tests\Integration\Database\DatabaseTestCase` and overrides the
existing `DatabaseMigrations` command options:

```php
protected function migrateFreshUsing(): array
{
    return [
        '--seed' => false,
        '--realpath' => true,
        '--path' => [Passkeys::migrationPath()],
    ];
}
```

This folds the shipped migration into the same `migrate:fresh` command that wipes and rebuilds each
database. Do not use `defineDatabaseMigrations()` with `loadMigrationsFrom()`: that hook runs before
`DatabaseMigrations` invokes `migrate:fresh`, so its table would be wiped. Do not include
`--database`; `DatabaseTestCase` does not use `RefreshDatabase` and therefore has no
`getRefreshConnection()` method. Do not use `#[WithMigration]`: it loads named Testbench migration
sets rather than arbitrary package paths. The four leaves remain empty except for their matching
`#[RequiresDatabase]` attribute. The suite needs only the `passkeys` table, so it does not load
framework migrations or create a users fixture table.

The shared suite proves:

- a 1,023-byte raw ID encodes to 1,364 characters and persists;
- hydration returns the exact PHP string on every driver;
- exact `credential_id` lookup and `VerifyPasskey::getPasskey()` find it;
- an exact duplicate is rejected by the unique index;
- two valid encoded values differing only by letter case can coexist.

The existing `CustomRouteKeyPasskey` feature coverage remains the sole owner of the custom
credential-ID route-key contract; do not duplicate it per database driver.

Do not change `.github/workflows/databases.yml` or environment files; the service matrix and local
variables already cover these directories.

### 6. Reject impossible assertion lookups (`passkeys-13`)

At the start of `VerifyPasskey::getPasskey()`, before Base64URL encoding or query construction:

```php
// Assertion ceremonies do not run CheckCredentialId, so enforce its CTAP2 limit before lookup.
if (strlen($credential->rawId) > 1023) {
    throw InvalidPasskeyException::make(
        'Passkey not recognized. It may have been removed from your account.',
    );
}
```

Use the same non-enumerating message as a lookup miss. In `VerifyPasskeyTest`, flush and enable the
real connection query log, call public `getPasskey()` with an oversized ID, and assert the log
remains empty after rejection. Then call it with a normal-sized missing ID and assert the log is
nonempty, proving the logger was active and the normal lookup reached Eloquent. Do not mock the
resolver, connection, or builder: that would test a double rather than the production path. Do not
use `expectException()` for either call because PHPUnit would end the method at the first throw.
Wrap each call in `try` / `catch (InvalidPasskeyException)`, put `$this->fail()` immediately after
each call, and run its log assertion after the catch. Do not add request validation rules: they
would duplicate the lookup owner, would not constrain the attested ID used for registration, and
would only avoid a decode after the server has already accepted the body. Do not add shared
constants or a credential-ID helper for the two protocol-defined literals.

### 7. Translate expected WebAuthn rejections (`passkeys-07`)

Catch the library's semantic base exception only around each validator call:

```php
// Resolve factory configuration outside the rejection boundary.
$validator = WebAuthn::attestationValidator();

try {
    return $validator->check(/* existing arguments */);
} catch (WebauthnException) {
    throw InvalidPasskeyException::make('Unable to register passkey. Please try again.');
}
```

Use the corresponding verification message in `VerifyPasskey::validate()`. This includes
`AuthenticatorResponseVerificationException` and assertion-only `CounterException`. Keep stored
credential deserialization and validator/factory construction outside the catch, and do not catch
`Throwable`, `RuntimeException`, database/listener failures, or separately rooted metadata-service
exceptions. This preserves visibility when the boot-time ceremony factory callback itself fails.

Extend both action tests to prove the mapped library rejection and unrelated runtime-failure
escape. For the counter case, first prove the same fixture reaches a raw `CounterException`, then
prove the action translates it. Extend registration, login, and confirmation controller tests only
enough to prove the package's unprocessable/non-enumerating HTTP boundary with
`assertUnprocessable()` and that the action was invoked once; avoid duplicating all action-level
cases.

Registration requires a minimal serializer-valid, validator-invalid credential payload plus real
serialized registration options before the action can run. Add the registration counterpart to
the existing `WebAuthnFixtures` HTTP payload helpers using `spomky-labs/cbor-php`, declare that
already-installed package as a root `require-dev` dependency, and emit `attStmt` as an empty CBOR
map. Keep malformed-format handling as its own exact-message test, correct the expired-session test
to use the structural credential without options, and add a separate mocked action-rejection test.
Pin the two colliding login request messages and make login/confirmation verification doubles throw
the real verification message. Do not add a full validator-passing registration ceremony fixture
or duplicate confirmation expiry coverage already owned by the shared verification request test.

### 8. Reject an empty user-handle secret (`passkeys-08`)

Resolve once per call without memoizing:

```php
$secret = self::config()->string('passkeys.user_handle_secret');

if ($secret === '') {
    throw new RuntimeException('Passkey user handle secret must not be empty.');
}

return $secret;
```

Extend `PasskeysTest` for the configured value, app-key-derived package default, and explicit blank
failure. Clarify in the existing config and Fortify guide that the secret must be nonempty. Do not
add key rotation, versions, multi-key verification, automatic migration, or another static slot.

### 9. Correct the orphanable owner type (`passkeys-09`)

Change only the model PHPDoc:

```php
/** @property-read null|PasskeyUser $user */
```

The `MorphTo` relation, `allowsLogin()`, owner resolution, and prune behavior already support null.
No null object, cast, wrapper, or runtime branch is needed.

### 10. Make orphan pruning fail closed (`passkeys-12`)

Simplify `PruneOrphanedPasskeys::pruneType()` while preserving the distinct operator remedies:

```php
$ownerClass = Relation::getMorphedModel($userType);

if ($ownerClass === null) {
    if (! class_exists($userType)) {
        if ($warn !== null) {
            $warn("Skipping passkeys for unresolved owner type [{$userType}]. Register the morph map before pruning.");
        }

        return 0;
    }

    $ownerClass = $userType;
}

if (! is_subclass_of($ownerClass, Model::class)) {
    if ($warn !== null) {
        $warn("Skipping passkeys for owner type [{$userType}] because it does not resolve to an Eloquent model.");
    }

    return 0;
}
```

Remove `deleteOwnerType()` and the backslash heuristic completely. Continue chunking known owner
types and querying owners without global scopes. Known missing owner rows are still deleted;
ambiguous types are retained.

Extend `PruneOrphanedPasskeysTest` and command coverage for mapped aliases, hyphenated unresolved
aliases, namespace-shaped unresolved aliases, renamed/unloadable FQCNs, loadable Eloquent FQCNs,
loadable non-model classes, known missing owners, dry-run counts, and warning/output. Do not add a
historical morph registry, prefix heuristic, alias naming rule, or destructive flag.

### 11. Complete static cleanup coverage (`passkeys-11`)

Extend `PasskeysStaticStateTest` through public behavior:

```php
Passkeys::authorizeLoginUsing(static fn (): bool => false);
$this->assertFalse(Passkeys::allowsLogin($request, $passkey));

Passkeys::flushState();

$this->assertTrue(Passkeys::allowsLogin($request, $passkey));
```

Use a real owned fixture so the result discriminates the callback from missing ownership. Do not
inspect private properties or add a public callback getter/static-slot registry.

### 12. Automate safe AAGUID refreshes (`passkeys-10`)

Add `.github/workflows/sync-passkeys-aaguids.yml` with weekly Monday and manual triggers. Use the
repository's normal major action tags and `phpswoole/swoole:6.2.0-php8.4` job container. The script
uses only core PHP/JSON functions already present in that image, so do not install Composer
dependencies or run the test workflow's extension setup. Check out `0.4` explicitly so a manual
dispatch from another ref cannot generate a mismatched update:

```yaml
- name: Install Git
  run: |
    apt-get update -qq
    apt-get install -y -qq git > /dev/null

- name: Checkout 0.4
  uses: actions/checkout@v6
  with:
    ref: "0.4"

- name: Trust checkout directory
  run: git config --global --add safe.directory "$GITHUB_WORKSPACE"

- name: Synchronize Passkeys AAGUIDs
  run: php src/passkeys/scripts/sync-aaguids.php

- name: Open update pull request
  uses: peter-evans/create-pull-request@v8
  with:
    base: "0.4"
    branch: sync-passkeys-aaguids
    delete-branch: true
    commit-message: "passkeys: synchronize AAGUID catalogue"
    title: "passkeys: synchronize AAGUID catalogue"
```

The container does not include Git, so install it before checkout and mark the checkout as safe for
the pull-request action's branch, commit, and push operations. Give the job only `contents: write`
and `pull-requests: write`. Keep the pull-request body focused on the upstream catalogue source. A
pull request created with the default `GITHUB_TOKEN` does not trigger the repository's test or
static-analysis workflows; its safety therefore comes from the script's checked atomic publication
and review of the generated diff. Deliberately do not use a personal access token merely to
retrigger CI: that would add secret management and a broader-privilege credential for this
generated data file. Do not install the application, fetch data at runtime, add a framework
scheduler command, or introduce cache invalidation.

### 13. Document the supported Passkeys surface (`passkeys-03`, `passkeys-06`, `passkeys-08`)

Extend only the existing Passkeys section of `src/boost/docs/fortify.md`, in Laravel documentation
style, with compact subsections/examples for:

- custom `getPasskeyDisplayName()` and `getPasskeyUsername()` values;
- `Passkeys::authorizeLoginUsing()`;
- user-bound `GenerateVerificationOptions` / `VerifyPasskey` calls;
- rebinding the five ceremony actions (`GenerateRegistrationOptions`, `StorePasskey`,
  `GenerateVerificationOptions`, `VerifyPasskey`, and `DeletePasskey`) and configuring ceremony
  steps/authenticators with `WebAuthn::configureCeremonyStepManagerFactoryUsing()` during boot;
  use singleton bindings for the stateless package actions and name the stateful exception;
- rebinding `PruneOrphanedPasskeys`, which the console command resolves separately from the
  container;
- rebinding the four response contracts, accurately noting that registration is transient while
  login, confirmation, and deletion responses are singleton bindings;
- extending `Passkey` and registering it with `Passkeys::usePasskeyModel()` during boot;
- `Passkeys::ignoreRoutes()` for custom route ownership;
- `PasskeyRegistered`, `PasskeyVerified`, and `PasskeyDeleted` events.

Keep existing guard, polymorphic-owner, request-aware callback, and worker-lifetime guidance.
Update the custom-migration paragraph to require at least 1,364 case-sensitive credential-ID
characters, and state that `user_handle_secret` must be nonempty. Do not add upgrade history,
factory internals, duplicate package README documentation, or another Passkeys page.

### 14. Complete audit records

Before implementation, replace all three core-plan routing lines:

- set the active work to `passkeys` and name this detail plan;
- name `Complete Fortify correctness, current parity, and configuration contracts` as the ledger
  entry required for the shared guide and `fortify.passkeys` bridge;
- carry Fortify revalidation of that guide and bridge into the active work.

After implementation and review:

- add one compact `Complete Passkeys correctness, security, and maintenance` ledger entry with all
  findings, rejected alternatives, tests, API/performance impact, and Fortify revalidation;
- mark `passkeys` complete in the core checklist;
- set the routing index to no active package and carry only genuine pending cross-package work;
- do not copy conversational history or the ignored `.tmp` report into tracked records.

## Test plan

| Surface | Verification |
|---|---|
| AAGUID script | Two successful publications replace contents exactly with mode 0644; a deterministic rename failure reports the error and removes its temporary file. |
| Package metadata | Removing any explicit direct requirement or drifting any root-aligned extension or library constraint fails. |
| Config ownership | Package defaults resolve through provider merging; validation still rejects invalid values/resolver output. |
| Registration owner | Direct creation rejects A's credential record for B with no inserted row; real session login switching preserves the stale A options that make this path reachable. |
| Credential schema | Maximum-size IDs persist/hydrate/lookup exactly, exact duplicates fail, and case variants coexist on all four databases. |
| Assertion size | A raw ID over 1,023 bytes produces the normal miss exception and zero queries; a normal-sized miss positively proves query logging and lookup remain active. |
| WebAuthn rejection | Library ceremony and a positively proven counter rejection map to validation; unrelated failures still surface. |
| Handle secret | Normal/default secrets work and an explicit empty value fails before HMAC generation. |
| Owner typing/pruning | Known missing owners are deleted; every ambiguous/unloadable type is warned and retained. |
| Static lifecycle | `authorizeLoginUsing()` affects behavior before flush and cannot leak after flush. |
| AAGUID workflow | Git setup, syntax, script path, permissions, checkout ref, and base branch are pinned. |
| Documentation | Fresh review checks the canonical Fortify guide against the final public APIs, configuration, and extension surfaces; no brittle Markdown-structure test is added. |

Run each changed test file immediately. Run all Passkeys tests after the coherent package slice.
Run the four database groups through `bin/run-database-tests.sh` when their services are available;
the ordinary full suite may skip unavailable services through `#[RequiresDatabase]`. Run
`composer fix` once at the final checkpoint. Do not separately rerun formatter, PHPStan, and the
parallel suite before that checkpoint.

## API, performance, and complexity assessment

- No public signature, named argument, configuration key/shape, response contract, action seam,
  guard behavior, custom model hook, or route-key contract changes.
- Registration adds one constant-time comparison after validation. User-handle derivation adds one
  strict empty-string comparison before its existing HMAC.
- Verification adds one O(1) string-length read and can avoid a guaranteed-useless database query.
- Successful WebAuthn validation adds only a `try` region; exception creation/translation occurs on
  rejection paths.
- The credential index remains one exact unique lookup with no extra round trip, hash, collision
  branch, cast, or retained duplicate identity.
- Atomic publication and scheduled synchronization execute only in repository maintenance, never
  in an application worker.
- Pruning removes a destructive bulk-query branch and adds no normal known-owner query.
- Metadata, docs, PHPDoc, workflow, and tests add no application runtime cost.

## Rejected designs

- PostgreSQL `bytea` / schema `binary()`: returns stream resources and breaks the public string
  attribute on PostgreSQL.
- SHA-256 digest column: duplicates identity and adds hashing, a mutator/cast, collision checking,
  and another schema field without need.
- UTF-8 1,364-character unique index: exceeds MySQL's key limit and can compare Base64URL
  case-insensitively.
- Driver-specific migrations or prefix indexes: unnecessary with the existing charset modifier.
- Request-layer credential-size rules: duplicate the assertion lookup owner and do not protect the
  attested registration ID.
- Narrow exception union or catch-all `Throwable`: respectively misses library-defined ceremony
  rejections or hides real server failures.
- Session/ceremony owner registries and locks: add mutable state while protecting fewer callers
  than the action-owned handle comparison.
- User-handle secret memoization/rotation machinery: adds worker state or credential migration for
  no approved contract.
- Morph-name heuristics, historical alias registries, or destructive flags: cannot make an
  ambiguous stored string authoritative.
- Runtime AAGUID fetching, retries, scheduler integration, backup protocols, or generic filesystem
  services: no application consumer or runtime need.
- A second Passkeys documentation page, README duplication, or historical upgrade prose: creates
  drift instead of documenting the current API once.

## Completion criteria

- Every accepted finding is implemented at its owning boundary and every superseded branch is
  removed.
- Every regression fails against the old behavior for the stated reason.
- SQLite, MySQL, MariaDB, and PostgreSQL prove the credential column contract.
- Existing Passkeys, Fortify, guard, coroutine, custom model/route-key, response, and event behavior
  remains green.
- `composer fix` passes.
- Fresh self-review finds no incomplete caller, stale comment, dead code, duplicate default,
  avoidable hot-path cost, broken Laravel API, or speculative machinery.
- Independent code review signs off before completion records and commits.
