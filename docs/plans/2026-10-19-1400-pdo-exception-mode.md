# PDO Exception Mode and Database Failure Classification

## Purpose and working context

Require exception mode for PDO connections and classify database failures without mistaking application, filesystem, or HTTP failures for database errors. Preserve Laravel-style query exceptions and the existing extension points for non-PDO drivers.

Work in `/home/binaryfire/workspace/contrib/hypervel/components-db`, branch `require-pdo-exception-mode`, based on `0.4`. Dependencies and `.env` are installed independently of the main checkout. Run commands from this worktree. After compaction, read the monorepo root `CLAUDE.md`, this worktree's `AGENTS.md`, and this plan in full; consult the affected source, tests, and documentation before editing. This is a framework enhancement with related correctness fixes.

**Status:** implementation and peer code review are complete, with all behavior differences approved. Complete the authorized PR workflow: commit and push, review CI and bot findings through two rounds, address agreed corrections, merge into `0.4`, update the main checkout and remove this worktree if safe. Targeted and database integration verification passed; the full-suite reruns were stopped after a worker stalled and must be reported as incomplete.

## Contract and boundaries

The approved changes are:

- The outer transaction retry decision accepts `DeadlockException` as a retryable failure, including one explicitly thrown by application code, while retaining successful cleanup, transaction level zero and the attempt limit as prerequisites.
- Connections created through the framework's PDO connector reject warning and silent error modes. Exception mode remains the default. Other PDO options remain configurable.
- Default database error detectors stop treating arbitrary non-PDO exception messages as database failures. Custom drivers use the existing detector bindings or protected connection hooks to identify their own failures.
- MySQL and MariaDB invalid-value classification does not turn an external-service failure into an `InvalidValueException` merely because its message contains MySQL error text.

Preserve `QueryException` wrapping, its original cause and query context, public method signatures and boolean return contracts, nested query-cause detection, application-bound detector policies, transaction ownership, and cancellation propagation. `Connection` stays driver-neutral. No new public API, exception hierarchy, configuration flag, PDO wrapper, or recovery registry is needed. Any additional public Laravel API departure requires owner approval under `AGENTS.md`.

Apply mode validation at physical connection creation, including reconnects. Custom connectors that bypass the base connector, supplied PDO handles, and their lazy factories must return exception-mode PDOs. Document this requirement; do not validate every constructor argument, setter, borrowed handle, or query. Changing the mode afterward through raw PDO access is unsupported. This is a supported-use contract, not an attempt to prevent deliberate low-level bypasses.

## Evidence and references

| Finding | Consequence and design |
|---|---|
| `Connector` and `PostgresConnector` already default to exception mode, but `getOptions()` permits overrides. | Validate the effective options before connecting; changing defaults alone does nothing. |
| A duplicate SQLite insert in silent mode, or warning mode with warnings suppressed, returns false. A transaction can then commit earlier work and report completion. | Reject those modes at connection creation. Do not add Eloquent or transaction workarounds for false writes. |
| A `StatementPrepared` listener's missing-file warning becomes `ErrorException`. The existing detector matches “No such file or directory,” reconnects, and loses a temporary table even inside `withPinnedSession()`. | Distinguish actual driver failures from ordinary warnings in the shared default detector. |
| An HTTP `RequestException` includes response-body text. A response containing “database is locked” makes `transaction(..., 2)` repeat its callback. A wrapped listener failure containing “Redis server went away” makes the worker stop. | Fix both default detectors, covering their transaction, queue, lock, and query consumers without separate patches at each caller. |
| MySQL's invalid-value regex accepts an HTTP failure containing `General error: 1366`. `ImplicitRouteBinding` catches `InvalidValueException` and turns it into a missing-model response. | Require an underlying PDO failure before MySQL invalid-value classification; preserve a normal `QueryException` for the HTTP failure. |
| mysqlnd can emit native PHP warnings even in PDO exception mode. The framework error handler converts them to `ErrorException`. | Keep narrow recognition of genuine client-warning forms; a blanket `PDOException`-only lost-connection policy is incorrect. |

Reference sources:

- [PHP error handling](https://www.php.net/manual/en/pdo.error-handling.php): exception mode is the default from PHP 8.0; requiring it does not remove PHP's general warning mechanism.
- PHP's [mysqlnd wire protocol](https://github.com/php/php-src/blob/PHP-8.5/ext/mysqlnd/mysqlnd_wireprotocol.c), [frame codec](https://github.com/php/php-src/blob/PHP-8.5/ext/mysqlnd/mysqlnd_protocol_frame_codec.c), and [authentication](https://github.com/php/php-src/blob/PHP-8.5/ext/mysqlnd/mysqlnd_auth.c) emit the two native-warning beginnings below through `php_error`, without a function-name prefix. [PDO error handling](https://github.com/php/php-src/blob/PHP-8.5/ext/pdo/pdo_dbh.c) preserves an already-pending exception. Hypervel's conversion is in `src/foundation/src/Bootstrap/HandleExceptions.php::handleError()`.
- [Doctrine DBAL's PDO connection](https://github.com/doctrine/dbal/blob/4.5.x/src/Driver/PDO/Connection.php) explicitly sets exception mode during initialization. Symfony's usual relational database integration uses Doctrine. Hypervel will reject incompatible configuration instead of silently overriding it.
- [Tempest's PDO connection](https://github.com/tempestphp/tempest-framework/blob/main/packages/database/src/Connection/PDOConnection.php) constructs PDO with only the DSN, username, and password, relying on PHP's exception-mode default. It passes no options array and exposes no error-mode setting through this connection path. Its [MySQL configuration](https://github.com/tempestphp/tempest-framework/blob/main/packages/database/src/Config/MysqlConfig.php) likewise has no PDO attributes. This differs from Hypervel's explicit rejection of incompatible options.
- Compare Laravel's checked-out source in `/home/binaryfire/workspace/references/laravel/framework`, particularly `Connection`, the two detectors, `Connectors/Connector`, `MySqlConnection`, `Queue/Worker`, and the corresponding tests. Its broad exception wrapping is intentional application-facing context and is retained.

Native lost-session probes under Swoole hooks produced structured `PDOException`s for ordinary MySQL, MariaDB, and PostgreSQL disconnects. PostgreSQL can report `HY000` with a lost-connection message rather than an `08xxx` code. Keep the existing message fallbacks for genuine PDO errors; do not replace them with a SQLSTATE-only detector. Native-warning tests do not need a malformed-protocol server or dependency workaround.

## Implementation

### 1. Validate connector options

In `src/database/src/Connectors/Connector.php::createConnection()`, before attempting a connection:

```php
if (($options[PDO::ATTR_ERRMODE] ?? PDO::ERRMODE_EXCEPTION) !== PDO::ERRMODE_EXCEPTION) {
    throw new InvalidArgumentException('Database connection [options] must use PDO::ERRMODE_EXCEPTION.');
}
```

Keep the existing default option arrays in `Connector` and `PostgresConnector`. Keep `getOptions()`, `getDefaultOptions()`, `setDefaultOptions()`, connector signatures, and unrelated option precedence intact. Missing error-mode options use PHP's default. Other invalid PDO attribute inputs retain native validation rather than acquiring a new general option validator.

All built-in connectors reach this method: MySQL/MariaDB before their session setup, PostgreSQL with its own defaults, and SQLite before its pragmas. The same boundary covers effective options selected for read/write endpoints and reconnects. Do not duplicate validation in each connector or the connection factory. Preserve connector cancellation unwrapping and retry behavior.

### 2. Correct default recovery classification

Keep the contracts and `DetectsLostConnections` / `DetectsConcurrencyErrors` traits unchanged so application bindings remain authoritative, including bare-container fallback behavior.

In `LostConnectionDetector::causedByLostConnection()`, retain the existing loop that unwraps consecutive `QueryException` causes. Then:

```php
// mysqlnd can report protocol failures as PHP warnings even in exception mode.
if ($e instanceof ErrorException) {
    return Str::startsWith($e->getMessage(), [
        'Packets out of order. Expected',
        'Error while sending',
    ]);
}

if (! $e instanceof PDOException && ! $e instanceof LostConnectionException) {
    return false;
}

// Continue with the existing message list for eligible database exceptions.
```

Keep the full existing message list for PDO failures and `LostConnectionException`. Add imports using existing conventions. Prefix recognition applies only to the two evidenced native warnings, not generic filesystem warnings or PDO warning-mode `SQLSTATE` messages. Do not add a warning-origin parser, stack-trace inspection, special error handler, or new warning forms without evidence.

In `ConcurrencyErrorDetector::causedByConcurrencyError()`, move the existing consecutive-`QueryException` unwrapping before classification and reject a non-`PDOException` cause. Then retain the SQLSTATE, native driver-code, and message checks. Remove the now-redundant outer `instanceof PDOException` block rather than nesting a new guard around it. `DeadlockException` already extends `PDOException` and preserves metadata; the default detector needs no special branch.

In `ManagesTransactions::handleTransactionException()`, accept `DeadlockException` as an already-classified concurrency failure at the outer retry decision. A nested transaction creates that wrapper only after its driver policy accepts the original failure; a custom type-based policy may not recognize the framework wrapper. Keep successful cleanup, transaction level zero and the attempt limit as prerequisites. Preserve initial classification, wrapper construction, cancellation and commit handling. This fixes both bound detectors and protected driver overrides without requiring either to recognize framework wrappers.

Do not unwrap arbitrary exception chains here: `Worker::stopWorkerIfLostConnection()` already walks the chain and identifies eligible database wrappers/causes. Retain that traversal and the worker's handling of custom detectors. Do not let a bound database detector see every unrelated transport exception thrown directly by a job. No source changes are expected in `Worker`, `DatabaseQueue`, `DatabaseLock`, or the connection retry hooks; verify their behavior through the tests below.

Do not add a `PdoConnection`-specific type gate: it would miss shared consumers and prevent application-bound detectors from classifying custom exceptions. Keep the existing policy for nested genuine query failures; distinguishing which connection owns a nested listener failure would introduce a separate behavior change without a demonstrated need for this plan.

Custom-driver recovery remains explicit. Applications may bind implementations of `Hypervel\Contracts\Database\LostConnectionDetector` and `ConcurrencyErrorDetector`, extending the defaults to preserve built-in behavior. Such implementations must inspect underlying causes rather than query SQL/bindings. A driver's existing protected recovery overrides can control driver-local decisions, but they do not replace the shared detector policy used by workers and locks. Do not force non-PDO drivers to manufacture `PDOException`s.

### 3. Correct MySQL invalid-value classification

In `MySqlConnection::isDataTypeError()`, unwrap consecutive `QueryException`s into a local cause, require `PDOException`, then apply the existing SQLSTATE and message checks to that cause:

```php
$cause = $exception;

while ($cause instanceof QueryException && $cause->getPrevious() !== null) {
    $cause = $cause->getPrevious();
}

if (! $cause instanceof PDOException) {
    return false;
}
```

This also prevents SQL or binding text in a nested wrapper from producing a false classification. Keep the existing method signature and the original exception passed to `newQueryException()`; only classification examines the unwrapped cause. MariaDB inherits the correction. Keep genuine PDO message fallbacks and existing subtype metadata.

Do not add a shared exception-origin abstraction for these small loops. PostgreSQL's strict string SQLSTATE checks already exclude ordinary integer-code exceptions. The audit found no demonstrated harmful path requiring changes to the unique-constraint classifiers; do not change them merely for symmetry. Newly verified defects still follow the normal investigation and peer workflow.

### 4. Remove obsolete fallback logic

In `PdoConnection::executeSessionStatement()`, replace the `exec($sql) === false` branch and its synthetic exception with `$pdo->exec($sql)`. Keep the catch that marks the physical session unknown and rethrows the original failure. The false-return branch supported the removed error modes.

Retain these distinct contracts:

- `statement()`, `unprepared()`, insert methods, and their public boolean return types. In particular, keep `unprepared()`'s Laravel-style comparison; this is not a return-type redesign.
- `ping()`'s `PDOStatement|false` handling and failure result.
- False-result handling for quoting and last-insert IDs; those native capabilities are not made infallible by exception mode.
- Driver-neutral `Schema\Builder` false-result handling, including custom-driver statement behavior.
- General PHP `ErrorException` handling elsewhere in the framework.

## Tests and verification

Read the existing tests before editing source and classify every generic-exception lost-connection/deadlock fixture, including tests that would still pass after losing their premise. Preserve every distinct assertion about retries, cleanup, exception identity, state, cancellation, custom policies, and events. Do not change exception fixtures mechanically: separate actual PDO failures, still-supported native PHP warnings, application failures, and non-PDO driver failures. Run each edited test file immediately from this worktree.

### Focused coverage

| Test file under `tests/` | Required work |
|---|---|
| `Database/DatabaseConnectorTest.php` | One provider for warning and silent options through the real SQLite connector; assert rejection and its message. Existing option-resolution coverage proves default/explicit merging, and existing connection tests cover accepted defaults. No duplicate matrix for every connector or separate `setDefaultOptions()` rejection test. |
| `Database/DatabaseConnectionLostTest.php` | Cover rejected direct/wrapped non-database failures, nested genuine PDO causes, both recognized mysqlnd warnings, and the existing explicit lost-connection signal. Include an unrelated warning containing a recognized phrase later in its message to establish prefix matching. Replace the old default-custom-message expectation with rejection; existing service-provider binding/trait tests already prove explicit custom policies, so add no duplicate policy test here. |
| `Database/ConcurrencyErrorDetectorTest.php` | Preserve genuine PDO code/message cases and nested wrapper protection. Reject an actual HTTP `RequestException` whose response body contains `database is locked`, including a wrapped form. The custom `RuntimeException` code-40001 row and generic-message positive case must no longer pass by default. Existing service-provider and transaction tests already cover bound policies; add no duplicate custom-policy test here. |
| `Database/DatabaseConnectionTest.php` | Keep `testBeginTransactionMethodRetriesOnFailure` and its `ErrorException` type; use `Error while sending QUERY packet. PID=1234`, a real exception-mode client-warning shape. Change `testTransactionMethodRetriesOnDeadlock`'s generic cause to `PDOException`, retaining its three-attempt assertions. These are deliberate adaptations of upstream fixtures to the approved detector policy. Non-PDO streaming and nested-transaction fixtures that need recovery must explicitly implement that driver's policy; keep their non-PDO exceptions. Add one neutral-driver nested retry regression: the inner transaction throws a query-wrapped custom failure on the first attempt, the outer transaction retries and completes at level zero. Confirm it fails before the transaction fix. PDO deadlock fixtures currently using generic `Exception`/`RuntimeException` should model actual PDO failures while retaining their retry/cleanup assertions. |
| `Database/DatabasePdoConnectionTest.php` | Add one focused `StatementPrepared` listener failure case with a filesystem-style `ErrorException`: use a reconnector that throws on unexpected invocation, assert the same PDO remains in use, and preserve the original cause in `QueryException`. Type variants belong in the detector providers; no extra temporary-table fixture is needed. Cover MySQL invalid-value false classification with a real HTTP `RequestException`; include nested query context containing the matching phrase and a genuine nested PDO invalid-value failure. Assert each expected exception subtype and preserve the outer cause. Prefer existing fixtures and a local test subclass over a new production seam. |
| `Database/DatabaseSessionConfiguratorTest.php` | Strengthen the ordinary-exception transaction test with the real HTTP failure: `transaction(..., 2)` runs the callback once and preserves the failure. Keep bound-detector retry coverage. Its simulated server-deadlock fixture currently wraps generic `Exception`; model the actual PDO failure without weakening session invalidation/savepoint assertions. |
| `Database/DatabaseServiceProviderTest.php` | Preserve binding overrides and bare-container fallback coverage. The latter should use a PDO lost-connection cause; its purpose is fallback resolution, not arbitrary-message acceptance. |
| `Database/DatabaseSchemaBuilderTest.php` | The foreign-key restoration failure's PDO `exec` mock must throw a prebuilt `PDOException` rather than return false. Assert identity and retain session non-reusability and suppression-depth cleanup checks. Keep the distinct callback-plus-restoration chaining test and driver-neutral `statement(false)` tests. |
| `Database/DatabaseSQLiteBuilderTest.php` | Replace the foreign-key restoration PDO helper's boolean trigger/false result with a nullable `PDOException` thrown at restoration. Preserve committed-schema and unusable-session assertions. Update the writable-schema reset and restoration mocks to propagate prebuilt PDO exceptions by identity rather than the removed synthetic message; retain ordering and the assertion that schema reload never runs after reset failure. |
| `Queue/QueueWorkerTest.php` | Replace the unsupported PDO-warning message with a genuine mysqlnd warning. Add wrapped listener runtime/file-warning failures to the existing negative provider, asserting that the next job runs and the worker does not mark the connection lost. Move its direct `transport failure` row into coverage with a bound message-matching detector: it must still prove the worker's own type filter blocks unrelated job failures, even when that detector would accept the message. Keep bound custom-driver positives, nested causes, actual PDO failures, and the opt-out behavior. |

Review sibling test fixtures across the repository before declaring the migration complete. In particular, `testManagedTransactionDoesNotRetryWhenRollbackCleanupFails` needs a genuine PDO deadlock so its single-attempt assertion still proves that failed cleanup prevents an otherwise valid retry. The neutral `testNestedDeadlockRollbackCleanupPreservesCancellation` needs explicit concurrency classification so it still exercises cancellation during deadlock handling. Do not add custom policies indiscriminately to every test connection and hide negative default-detector coverage.

Reuse existing cancellation tests in `DatabaseConnectorTest`, `DatabaseConnectionTest`, `DatabasePdoConnectionTest`, and `QueueWorkerTest`, including begin/commit/rollback cleanup and streaming cancellation. Do not create a new asynchronous harness or change existing dependency-bug skips for this work.

### Real databases and final checks

Use the existing integration suite against MySQL, MariaDB, PostgreSQL, and SQLite. `bin/run-database-tests.sh <driver> -p <workers>` runs `tests/Integration/Database` and the matching package-specific database directories. Set the appropriate `DB_*` values for each service; do not assume the copied environment selects every driver. Use isolated test databases and a bounded worker count compatible with the configured services. Never kill other sessions or run capacity benchmarks as part of this change.

Relevant existing integration coverage includes `UniqueConstraintViolationTest`, `InvalidValueExceptionTest`, `DatabaseLockTest`, `ConnectionLockTimeoutTest`, `NestedTransactionRetryTest`, `TransactionsTest`, and `DatabaseTransactionsTest`. Together these cover real failed writes, unique/invalid-value classification, lock contention and expiry, transaction rollback, retries, and completion callbacks. Do not duplicate these with a new large failed-write suite. A configured unreachable service is a failure, not a skip; report unavailable verification accurately.

`.github/workflows/databases.yml` already invokes that runner for all four drivers. Keep new common regression tests in existing discovered locations. Change CI only if new coverage would otherwise be missed.

Verification order:

1. Run each changed test file immediately: `./vendor/bin/phpunit --no-progress <file>`.
2. Run focused multi-file checks while resolving failures. Inspect meaningful failures before adjusting tests; verify any behavior change with the peer under `AGENTS.md`.
3. Because shared recovery policy affects consumers throughout the framework, run all the gates in `composer fix`: formatting, full analysis, parallel tests, Testbench, and dogfood checks, sequentially and once. The aggregate command cannot pass a worker limit only to its test scripts. When its default CPU-based concurrency exceeds the configured services' limits, run the same component commands separately: `composer lint:fix`, `composer analyse`, `composer test:parallel -- --processes=<workers>`, and `composer test:testbench -- --processes=<workers>`. Then run the installs and tests listed in `test:dogfood`, adding `--processes=<workers>` only to the parallel Testbench package's `composer test` invocation; the AI dogfood test is serial. Use the long `--processes` option through Testbench because its command consumes `-p` as the parallel flag. Do not edit runner code or repository scripts to impose a local resource limit, and do not run `composer fix` again after its equivalent gates pass.
4. Run the four database integration suites sequentially with their service configuration. After corrections, rerun the failed check and remaining checks; repeat earlier checks only when the correction can affect them.
5. Perform the final source/documentation audit, full self-review, and independent code review. No timing assertions, benchmark campaign, or unrelated dependency changes are required.

## Documentation and removal audit

- `src/docs/database.md`: explain the mode requirement in configuration, including explicit overrides and supplied/custom PDO obligations. In the extension section, explain the default detector policy and show how existing bindings support other drivers. The example must extend the default, unwrap consecutive `QueryException` causes before its custom-driver type check, and defer to `parent::` for other failures: queue reservation passes a query wrapper, transactions pass the thrown failure, and workers pass its immediate cause. Show the unwrapping loop rather than describing it only in prose. Read the surrounding Laravel-style prose; avoid repeating internal implementation details.
- `src/docs/porting-from-laravel.md`: state the required action for warning/silent settings and custom drivers that relied on generic message matching; link to the canonical database sections.
- `src/database/README.md`: record the deliberate mode/configuration and default-recovery differences concisely under Differences From Laravel, linking to the database documentation.
- `src/docs/queues.md`: its current lost-database-connection explanation remains accurate; edit only if the final implementation makes a specific statement wrong.
- `docs/todo.md`: remove the fulfilled database entry only when all its requirements have been implemented and verified. No new warning-mode fallback TODO remains.
- After merge and consumer adoption, retire any active consumer follow-up that asked for duplicate origin checks: callers already delegating to the bound detectors inherit the fix. Keep their cancellation/unwrap behavior and completed historical plans intact.

Search the whole repository, not only Database: source, tests, fixtures, stubs, configuration, documentation, CI, type fixtures, and benchmark runners. Review occurrences of `ATTR_ERRMODE`, `ERRMODE_WARNING`, `ERRMODE_SILENT`, warning-mode language, `ErrorException`, the two detector methods/contracts, and PDO false/error-info handling. Follow related callers rather than treating a zero-match search as proof. Exclude installed dependencies and generated/minified assets from source cleanup, and do not rewrite completed historical plans.

Classify remaining occurrences by purpose: rejection tests, supported native warnings, legitimate false-return contracts, unrelated warnings, or history. Remove stale imports, comments, test names and assertions that still promise configurable warning/silent support. Preserve current valid behavior and remove dead code completely; minimizing churn is not a reason to retain obsolete machinery. Do not turn this into an unrelated cleanup of every exception or regex in the framework.

## Performance, review, and completion

The mode check is one options lookup/comparison per physical connection attempt. It adds no per-query check, database round trip, pool bookkeeping, shared mutable state, or cache. Detector work runs only on error paths, with an existing linear walk through nested query wrappers followed by type checks and the existing message/code checks. Prefix recognition replaces broad matching for native warnings. No new polling, retries, allocations on successful query paths, or worker-lifetime storage is introduced.

Implementation uses direct changes in the existing owners and Laravel-style names/layout. New mechanisms need a verified requirement; churn and backwards compatibility with previous Hypervel versions do not justify inferior code. Preserve supported Laravel APIs except the approved policy changes. Resolve newly discovered real defects and disagreements through the task's peer workflow; bring additional API departures or meaningful performance/guarantee tradeoffs to the owner before implementing them.

Before requesting code review, self-review the complete diff against this plan, the TODO requirements, actual call paths, and test results. Request review of code, tests, documentation, and plan changes together; verify corrections and regressions until signoff. Stage and commit whole files only when authorized, with clear commit bodies. PR text must describe standalone framework behavior and rationale, without private-consumer details or internal review history. Do not claim CI or unrun integration coverage passed. A plan signoff alone does not authorize implementation or publication.
