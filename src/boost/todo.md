# Source Implementation Gaps

## Authentication

- Create hypervel/react-starter-kit
- Port Fortify package
- Port Passport package

## Artisan

- Add a `composer dev` script to the `hypervel/hypervel` application skeleton and the `hypervel/react-starter-kit` skeleton. The script should start the Hypervel development server and frontend asset watcher together using the package manager tools already included with each skeleton, so new applications have a simple one-command local development workflow.

## Configuration

## Boost

- Implement Hypervel Boost's installation flow and revisit the Boost section of `installation.md` once the implementation is complete. The current docs describe the intended `composer require hypervel/boost --dev` and `php artisan boost:install` workflow, but `src/boost` currently contains the documentation package only. Correct fix: add the interactive installer command and supporting tools, then update the installation docs for any differences from Laravel Boost.
- Publish a Hypervel AI agent playbook at `hypervel.org/for/agents`. The copied installation docs include a Laravel agent prompt section, but Hypervel does not yet have an equivalent public playbook. Correct fix: write and publish a Hypervel-specific Markdown guide covering installation, project layout, Swoole / coroutine considerations, testing, and package conventions before adding the agent-prompt section back to the installation docs.

## Documentation

- Re-run the introduction benchmarks against Hypervel 0.4 before publishing externally. The benchmark tables currently preserve the 0.3 results so the comparison is not lost during the docs port, but Hypervel 0.4's decoupled runtime should have fresh measurements before those numbers are treated as current.

## Collections

## Database

- Port Laravel's `hasAttached()` list-of-pivot-arrays conversion. The copied docs show passing a list of pivot attribute arrays to `hasAttached()`, but Hypervel currently passes that raw list through as pivot data. Laravel detects a non-empty list of arrays, counts the related factory when no count was set, and wraps the pivot rows in a `Sequence`. Correct fix: add that conversion branch to `Hypervel\Database\Eloquent\Factories\Factory::hasAttached()` and port Laravel's `test_belongs_to_many_relationship_with_pivot_arrays` coverage.
- Port Laravel's transactional pivot `OrFail` helpers. The copied Eloquent relationship docs show `attachOrFail()`, `detachOrFail()`, `syncOrFail()`, `syncWithoutDetachingOrFail()`, and `toggleOrFail()`, but `Hypervel\Database\Eloquent\Relations\Concerns\InteractsWithPivotTable` currently only has the non-transactional methods. Laravel also includes `syncWithPivotValuesOrFail()` and `updateExistingPivotOrFail()` in the same concern. Correct fix: port these wrappers so each operation runs inside the parent connection transaction, then port Laravel's matching relationship tests.
- Rename the MySQL upsert alias from `laravel_upsert_alias` to `hypervel_upsert_alias`. `Hypervel\Database\Query\Grammars\MySqlGrammar::compileUpsert()` still emits `laravel_upsert_alias` when `use_upsert_alias` is enabled. This is an internal SQL alias inherited from Laravel, but it is visible in generated SQL and should follow Hypervel naming. Correct fix: update both alias occurrences in `MySqlGrammar` and adjust any query grammar tests that assert the generated SQL.
- Make `DB::whenQueryingForLongerThan()` registration work correctly with pooled connections. The copied Laravel docs register the handler once in a service provider, but Hypervel's `DatabaseManager::__call()` currently forwards that call to the current borrowed connection. Pooled connections are separate worker-level resources, and `Connection::resetForPool()` clears query duration handlers when a connection is returned to the pool, so one boot-time registration does not reliably apply to request connections. Correct fix: make query-duration monitoring manager / pool aware so one boot-time registration applies to every pooled connection while query duration and "has run" state still reset per request / coroutine.
- Wire opt-in heartbeat support for database pools. `src/foundation/config/database.php` and the database docs advertise a `heartbeat` option in every database pool block, but `Hypervel\Database\Pool\PooledConnection` implements `Hypervel\Contracts\Pool\ConnectionInterface` directly and never consumes `PoolOption::getHeartbeat()`. Do not switch `PooledConnection` wholesale to `Hypervel\Pool\KeepaliveConnection`: that class uses a different `call()`-based lifecycle, makes `getConnection()` throw, stores the wrapped connection in a one-slot channel, treats `heartbeat <= 0` as a 10-second interval, and would bypass existing DB-specific release behavior such as state reset, transaction rollback, error-count handling, release events, and shared in-memory SQLite handling. Correct fix: keep `heartbeat => -1` as disabled with zero timer / ping overhead; when `heartbeat > 0`, have each worker-local `DbPool` start one timer for that pool, inspect only idle pooled connections, skip borrowed connections, close connections older than `max_idle_time`, and run a lightweight raw PDO ping such as `SELECT 1` on remaining idle connections without firing query events or mutating query logs / query-duration state. If the ping fails, close / discard the pooled connection so the next borrow creates a fresh connection. This is useful for long-lived workers behind load balancers, firewalls, NAT, or managed database proxies that drop idle TCP connections.

## Foundation

- Port Laravel's real-time facade loader. The copied facade docs show `Facades\App\Contracts\Publisher`, but Hypervel's `Hypervel\Foundation\Bootstrap\RegisterFacades` only registers configured aliases with `class_alias()` and has no equivalent of Laravel's `Illuminate\Foundation\AliasLoader` that intercepts `Facades\...` classes and generates facade stubs. Correct fix: port the alias loader using Hypervel namespaces, register it from `RegisterFacades`, generate real-time facade classes that extend `Hypervel\Support\Facades\Facade`, and add tests proving `Facades\App\Contracts\Publisher::shouldReceive(...)` resolves and mocks the underlying container binding.

## Horizon

- Wire SMS support for Hypervel Horizon long-wait notifications. The Horizon docs show `Horizon::routeSmsNotificationsTo(...)` and `Hypervel\Horizon\Horizon` stores the configured number, but `Hypervel\Horizon\Notifications\LongWaitDetected::via()` and `Hypervel\Horizon\Listeners\SendNotification` currently have the SMS / Nexmo route commented out because no SMS client is supported yet. Correct fix: add a supported SMS notification channel, route long-wait notifications to it when `Horizon::$smsNumber` is set, add the matching notification message method, document the channel prerequisite, and add coverage for mail, Slack, and SMS routing.

## Http

- Port FailOnUnknownFields form request support
- Port `Hypervel\Http\Client\Factory::failedRequest()` fake-response parity. The HTTP client docs show Laravel's supported `Http::fake(['github.com/*' => Http::failedRequest(...)])` pattern, but Hypervel currently returns a bare `RequestException` from `failedRequest()` and the fake stub path does not handle that exception return as a failed HTTP response. Correct fix: match Laravel's behavior so `failedRequest()` works inside `Http::fake([...])`, while preserving the existing ability to instantiate the exception directly, and port Laravel's matching HTTP client tests.

## Mail

- Port Cloudflare mail transport support. The copied mail docs include a Cloudflare driver section, but `Hypervel\Mail\MailManager` has no `createCloudflareTransport()` method and `Hypervel\Mail\Transport\CloudflareTransport` does not exist. Correct fix: port Laravel's `CloudflareTransport` to `src/mail/src/Transport/CloudflareTransport.php`, add `createCloudflareTransport()` to `MailManager` using `services.cloudflare.account_id` and `services.cloudflare.token` / `services.cloudflare.key`, add `cloudflare` to the pooled transport list, update the supported transport comments in the mail config files, and port Laravel's matching mail manager tests.

## Packages

- Port a `workbench:install` command for Hypervel Testbench. Hypervel has Workbench runtime support, but no scaffolding command for package authors to create the recommended `workbench/` directory and `testbench.yaml`. Correct fix: add an install command adapted to Hypervel's supported Workbench keys (`install`, `auth`, `health`, `sync`, and `discovers`), generate a sensible package-local Workbench skeleton, register the command through Testbench's command loader, and add command coverage.
- Investigate adding Spatie-style role and permission lookup helpers to the permission package. The package is based on `spatie/laravel-permission`, but currently lacks helpers such as `Role::findByName()`, `Role::findById()`, `Role::findOrCreate()`, `Permission::findByName()`, `Permission::findById()`, and `Permission::findOrCreate()`. Check Spatie's current implementation and decide whether these helpers should be ported for API parity, adapted for Hypervel's guard and cache behavior, or intentionally omitted.

## Pool

- Make `Hypervel\Pool\KeepaliveConnection` honor disabled heartbeat configuration. `PoolOption` documents `heartbeat => -1` as disabled, but `KeepaliveConnection::getHeartbeatSeconds()` currently turns any non-positive heartbeat into a 10-second interval and `addHeartbeat()` always creates a timer. Correct fix: only create the heartbeat timer when `PoolOption::getHeartbeat() > 0`; when heartbeat is `<= 0`, do not start a timer or run heartbeat work. Keep `max_idle_time` behavior separate from heartbeat.

## Routing

- Make `URL::defaults()` coroutine-safe. The URL generation docs show setting request-wide URL defaults from middleware, but `Hypervel\Routing\UrlGenerator::defaults()` mutates `Hypervel\Routing\RouteUrlGenerator::$defaultParameters` on the worker singleton. In Swoole workers, one request's defaults can leak or race into concurrent and later requests. Correct fix: store request-level named parameter defaults in `CoroutineContext`, preserve any intentional boot-time defaults, keep `getDefaultParameters()` reading the effective defaults, and add coroutine-isolation coverage.

## Queue

- Port debounced jobs. The copied queue docs document `#[DebounceFor]`, `debounceId()`, `debounceVia()`, and `Hypervel\Queue\Events\JobDebounced`, but the attribute, event, and debounce dispatch path do not exist in the current queue package. Correct fix: port Laravel's debounced job support, including cache coordination, max-wait behavior, superseded-job removal, the `JobDebounced` event, and the matching test coverage.
- Port `Hypervel\Queue\Attributes\Delay`. The copied event docs show `#[Delay(60)]` on a queued listener class, but the attribute does not exist in `src/queue/src/Attributes/` and `Hypervel\Events\Dispatcher::queueHandler()` does not read it. Delay is currently configurable only via the listener's `$delay` property or `withDelay()` method. Correct fix: port Laravel's `Illuminate\Queue\Attributes\Delay` as `Hypervel\Queue\Attributes\Delay`, wire it into `Dispatcher::queueHandler()` alongside the existing `Connection` and `Queue` attribute reads, and port the matching Laravel listener tests.
- Port `Hypervel\Contracts\Queue\PreparesForDispatch` and wire it into `Hypervel\Foundation\Bus\PendingDispatch::shouldDispatch()`. Laravel lets a job implement `prepareForDispatch()` and return `false` to abort dispatch before uniqueness locks are acquired; Hypervel currently has no contract and `PendingDispatch::shouldDispatch()` only checks `ShouldBeUnique`.
- Port queue interruption support. Laravel has `Illuminate\Contracts\Queue\Interruptible`, dispatches `WorkerInterrupted` when the worker receives `SIGQUIT`, `SIGTERM`, or `SIGINT`, and calls `interrupted($signal)` on the running queued command when it implements the contract. Hypervel's worker currently only flips `$shouldQuit` on those signals, has no `WorkerInterrupted` event, and never notifies the running command. Correct fix: add `Hypervel\Contracts\Queue\Interruptible`, port the event, track the current job/command path needed by `Worker::notifyJobOfSignal()`, dispatch the event, and call `interrupted($signal)` before the worker exits.

## Scheduling

- Port `schedule:pause`, `schedule:continue`, and the `evenWhenPaused()` event modifier. The copied scheduling doc documents temporarily pausing scheduled task processing without redeploying, but Hypervel has no `SchedulePauseCommand` or `ScheduleContinueCommand`, and `Hypervel\Console\Scheduling\ManagesAttributes` has no `evenWhenPaused()` method. Correct fix: port Laravel's pause / continue commands using a cache flag, add the event modifier and pending-attribute merge behavior, gate the `schedule:run` loop so paused events are skipped unless they opt in, and port Laravel's matching coverage.

## Support

- Port `Str::initials()` and fluent `Stringable::initials()`. The copied strings docs document `Str::initials('taylor otwell')` with a `capitalize` argument and `Str::of('Taylor Otwell')->initials()`, but neither method exists in `Hypervel\Support\Str` or `Hypervel\Support\Stringable`. Correct fix: port Laravel's `Str::initials()` implementation, add `Stringable::initials()`, and port Laravel's matching Support tests.

- Port `Hypervel\Support\Uri::authority()`. The copied helpers docs show `$uri->authority()` in the URI inspection example, but `Hypervel\Support\Uri` currently exposes `scheme()`, `user()`, `password()`, `host()`, `port()`, `path()`, `pathSegments()`, `query()`, and `fragment()` without the Laravel `authority()` method. Correct fix: add `authority(): ?string` returning the underlying URI authority and port Laravel's `SupportUriTest` coverage for user info, host, and authority inspection.

## Telescope

- Port Laravel's `telescope:install` command. The copied Telescope docs document `php artisan telescope:install`, but Hypervel currently only registers `telescope:publish`, `telescope:clear`, `telescope:pause`, `telescope:prune`, and `telescope:resume`. Hypervel already publishes the provider stub, config, and migrations under the `telescope-provider`, `telescope-config`, and `telescope-migrations` tags. Correct fix: port Laravel Telescope's install command with Hypervel namespaces, publish those three tags, register `App\Providers\TelescopeServiceProvider` in `bootstrap/providers.php` via `Hypervel\Support\ServiceProvider::addProviderToBootstrapFile()`, register the command in `Hypervel\Telescope\TelescopeServiceProvider`, and add command coverage.

## Testing

- Port an app-facing `php artisan test` command. The copied testing docs document `php artisan test`, including `--parallel`, `--coverage`, `--min`, `--profile`, `--recreate-databases`, `--drop-databases`, `--without-databases`, `--without-cache`, and ParaTest pass-through options such as `--processes`, but Hypervel currently ships only `make:test` for applications and `package:test` for Testbench package development. The underlying machinery already exists: `Hypervel\Testing\ParallelRunner`, `Hypervel\Testing\ParallelTesting`, parallel database / cache / view handling, and Collision's coverage / printer support used by Testbench's `package:test` command. Correct fix: add a Hypervel application test command, or a Hypervel Collision adapter, that shells out to PHPUnit / ParaTest using `Hypervel\Testing\ParallelRunner`, sets the `HYPERVEL_PARALLEL_TESTING_*` environment variables, preserves PHPUnit / ParaTest pass-through arguments, and port the matching command coverage.
- Port the `#[UnitTest]` testing attribute and no-boot test lifecycle. The copied testing docs reference `Hypervel\Foundation\Testing\Attributes\UnitTest` to skip booting the application for a single test method, but Hypervel currently has no `UnitTest` attribute and `Hypervel\Foundation\Testing\TestCase::setUp()` / `tearDown()` always call the framework lifecycle. Laravel implements this with `Illuminate\Foundation\Testing\Attributes\UnitTest` and a memoized `withoutBootingFramework()` check on the current test method. Correct fix: add the attribute class, add the per-method reflection check to Hypervel's test case lifecycle, skip application boot / teardown when present, preserve `RunTestsInCoroutine` behavior unless deliberately disabled by the test class, and port Laravel's coverage.
- Update the PHPUnit `make:test --unit` stub to use Hypervel's coroutine-aware application test case. The testing docs recommend extending `Tests\TestCase` for Hypervel application tests so they run through `RunTestsInCoroutine`, but `src/foundation/src/Console/stubs/test.unit.stub` currently extends raw `PHPUnit\Framework\TestCase`. Correct fix: change the unit stub to extend `Tests\TestCase`, keep `#[UnitTest]` as an optional per-method optimization for tests that should skip booting the application, and update the generator coverage.

## Validation

- Port Rule::string() fluent string rule builder

## Vite

- Port Laravel's recursive Vite import resolution. Laravel resolves static imports recursively via `Vite::resolveImports()`, while `Hypervel\Foundation\Vite::__invoke()` currently only preloads each entry chunk's direct `imports`, so nested imported chunks and nested CSS can be omitted from generated preload / stylesheet tags. Correct fix: port Laravel's recursive import resolver into `Hypervel\Foundation\Vite`, use it when collecting imports for an entry chunk, and port Laravel's nested import / nested CSS Vite tests.
- Align JavaScript module preload attributes with Laravel. Laravel includes `as="script"` on JavaScript `modulepreload` links, while Hypervel currently emits `rel="modulepreload"` without the `as` attribute. Correct fix: add the script preload `as` attribute in `Hypervel\Foundation\Vite::resolvePreloadTagAttributes()` and update the Vite tests that assert generated preload markup.
- Configure Hypervel's application skeleton and React starter kit with explicit Vite refresh paths. The Vite docs list Hypervel's default refresh paths without Laravel-only `app/Livewire/**`, but the current skeleton delegates to the Laravel Vite plugin's `refresh: true` defaults. Correct fix: update the skeleton `vite.config.js` files to pass the Hypervel-specific watch paths explicitly.
