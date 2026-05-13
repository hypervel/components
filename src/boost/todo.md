# Source Implementation Gaps

## Authentication

- Create hypervel/react-starter-kit
- Port Fortify package
- Port Passport package

## Artisan

- Create Hypervel Sail
- Port console command `Aliases`, `Help`, `Hidden`, and repeatable `Usage` attributes

## Configuration

- Improve cache-backed maintenance mode freshness across multiple servers without adding cache overhead to every request. `WorkerCachedMaintenanceMode` currently caches one maintenance snapshot for the worker lifetime, so `php artisan down` on one server may not be seen by already-running workers on other servers until those workers reload. Correct fix: keep the per-worker in-memory snapshot, but add a configurable refresh interval such as `app.maintenance.refresh_interval` / `APP_MAINTENANCE_REFRESH_INTERVAL` with a small default like `5` seconds. Each worker should only check the backing maintenance driver when the interval has elapsed; normal requests continue using memory. A value of `0` should likely mean "never poll, refresh only on local activate/deactivate or worker reload." This preserves high-traffic scalability while letting remote servers observe cache-backed maintenance mode within the configured interval.
- Wire pre-rendered maintenance mode output into the earliest HTTP request path. `DownCommand` writes `storage/framework/maintenance.php` when `--render` is used, and the docs say this view is returned before application dependencies load, but no source path currently includes that generated file before the framework boots. Correct fix: integrate the generated file into Hypervel's Swoole HTTP entry path, preserving maintenance bypass and excluded-path behavior from `src/foundation/src/Console/stubs/maintenance-mode.stub`.

## Authorization

- Add `Hypervel\Routing\Attributes\Controllers\Authorize`. Laravel has `Illuminate\Routing\Attributes\Controllers\Authorize`; Hypervel docs already reference the Hypervel equivalent, but the class does not exist. Correct fix: port Laravel's attribute, extending `Hypervel\Routing\Attributes\Controllers\Middleware` and using `Hypervel\Auth\Middleware\Authorize::using(...)`.
- Widen `Authorizable` ability types to accept `UnitEnum`. `Gate`, route `can()`, and `Authorize::using()` support enum abilities, but `Hypervel\Foundation\Auth\Access\Authorizable::can/canAny/cant/cannot` are typed as `iterable|string`, so `$user->can(Ability::UpdatePost)` currently TypeErrors. Correct fix: add `UnitEnum` to those method signatures and to `Hypervel\Contracts\Auth\Access\Authorizable::can`.
- Widen `Gate::allowIf()` / `Gate::denyIf()` `$code` type. Laravel allows arbitrary response codes; Hypervel's `Response` / `AuthorizationException` already support `int|string|null`, but `Gate::allowIf()` and `denyIf()` only accept `?string`. Correct fix: change those method signatures and facade docblocks to `int|string|null`.

## Blade

- Port Blade `@context` support. The copied Laravel Blade doc includes the `@context` / `@endcontext` directives, but Hypervel's `BladeCompiler` does not use Laravel's `CompilesContexts` concern and `src/view/src/Compilers/Concerns/CompilesContexts.php` does not exist. Hypervel already has the `context()` helper and `Hypervel\Support\Facades\Context`, so the correct fix is to port Laravel's compiler concern using Hypervel namespaces and add it to `BladeCompiler`.
- Complete `@use` support. Hypervel currently supports simple class imports and aliases, but the docs show grouped imports plus `function` and `const` imports. Laravel's `CompilesUseStatements` handles grouped imports and the `function` / `const` modifiers; Hypervel's implementation only splits the expression on commas and produces invalid output for those documented forms. Correct fix: port Laravel's newer parsing logic and the missing tests from Laravel's `BladeUseTest`.
- Port `@hasStack`. The docs include `@hasStack`, but Hypervel is missing Laravel's `compileHasStack()` method and `Hypervel\View\Concerns\ManagesStacks::isStackEmpty()`. Correct fix: add `compileHasStack()` to `CompilesConditionals`, add `isStackEmpty(string $section): bool` to `ManagesStacks` using the coroutine-backed push / prepend state, and port Laravel's Blade / stack tests.
- Port Laravel's `@fonts` Blade helper and related Vite fonts API if Hypervel wants full Blade / Vite parity. Laravel has `compileFonts()` in `CompilesHelpers` and a `Vite::fonts()` implementation; Hypervel has neither. This is not currently documented in `blade.md`, but it is a Laravel Blade helper that has not been ported.
- Add missing public Blade compiler API parity: `BladeCompiler::getPath()`, `BladeCompiler::setPath()`, and Laravel's nullable `compile($path = null)` behavior. Hypervel currently requires a string path and has no public getter / setter for the compiler path.
- Add missing `View::render(?callable $callback = null)` support. Hypervel has an internal `doRender(?callable $callback = null)` path, but the public `render()` method does not accept the optional callback that Laravel exposes.
- Bring `Hypervel\View\ComponentAttributeBag` closer to Laravel by implementing `Hypervel\Contracts\Support\Arrayable`, adding `toArray()`, supporting `all($keys = null)`, and using `Hypervel\Support\Traits\InteractsWithData`. Laravel exposes typed attribute access helpers through this trait; Hypervel's attribute bag currently lacks that API surface.
- Fix `CompilesComponents::compileProps()` helper variable cleanup. Laravel unsets `$__defined_vars`, `$__key`, and `$__value`; Hypervel currently only unsets `$__defined_vars`, so the generated component template can leak internal helper variables into scope.

## Collections

- Port Laravel's `Collection` / `LazyCollection` `newInstance()` extension hook. Laravel routes derived collection instances through a protected `newInstance($items = [])` method, while Hypervel currently uses direct `new static(...)` calls throughout the collection classes. Correct fix: add the protected `newInstance()` method and update the internal factory paths Laravel uses it for, so subclasses can control how derived collections are constructed.
- Port Laravel's variadic collection factory arguments. Laravel's `make`, `wrap`, `empty`, `times`, `fromJson`, and `range` APIs accept extra constructor arguments and forward them to the collection instance. Hypervel currently exposes stricter signatures and drops those extra args. Correct fix: update the signatures and internal construction to match Laravel's `...$args` behavior for both eager and lazy collections where Laravel supports it.
- Port `SortDirection` enum support in collection and array sorting APIs. Laravel accepts the global `SortDirection::Ascending` / `SortDirection::Descending` enum values in `Collection::sortBy()`, multi-column sort definitions, `sortKeys()`, `sortKeysDesc()`, `Arr::sortRecursive()`, and `Arr::sortRecursiveDesc()`. Hypervel currently supports the older bool / string direction forms only. Correct fix: port Laravel's enum handling and related tests.
- Port depth-aware `dot()` support. Laravel supports `Arr::dot($array, $prepend = '', $depth = INF)`, `Collection::dot($depth = INF)`, and `LazyCollection::dot($depth = INF)`. Hypervel currently flattens all nested levels and does not accept a depth argument. Correct fix: port Laravel's depth parameter and behavior across `Arr`, `Collection`, and `LazyCollection`.

## Database

- Make `DB::whenQueryingForLongerThan()` registration work correctly with pooled connections. The copied Laravel docs register the handler once in a service provider, but Hypervel's `DatabaseManager::__call()` currently forwards that call to the current borrowed connection. Pooled connections are separate worker-level resources, and `Connection::resetForPool()` clears query duration handlers when a connection is returned to the pool, so one boot-time registration does not reliably apply to request connections. Correct fix: make query-duration monitoring manager / pool aware so one boot-time registration applies to every pooled connection while query duration and "has run" state still reset per request / coroutine.
- Wire opt-in heartbeat support for database pools. `src/foundation/config/database.php` and the database docs advertise a `heartbeat` option in every database pool block, but `Hypervel\Database\Pool\PooledConnection` implements `Hypervel\Contracts\Pool\ConnectionInterface` directly and never consumes `PoolOption::getHeartbeat()`. Do not switch `PooledConnection` wholesale to `Hypervel\Pool\KeepaliveConnection`: that class uses a different `call()`-based lifecycle, makes `getConnection()` throw, stores the wrapped connection in a one-slot channel, treats `heartbeat <= 0` as a 10-second interval, and would bypass existing DB-specific release behavior such as state reset, transaction rollback, error-count handling, release events, and shared in-memory SQLite handling. Correct fix: keep `heartbeat => -1` as disabled with zero timer / ping overhead; when `heartbeat > 0`, have each worker-local `DbPool` start one timer for that pool, inspect only idle pooled connections, skip borrowed connections, close connections older than `max_idle_time`, and run a lightweight raw PDO ping such as `SELECT 1` on remaining idle connections without firing query events or mutating query logs / query-duration state. If the ping fails, close / discard the pooled connection so the next borrow creates a fresh connection. This is useful for long-lived workers behind load balancers, firewalls, NAT, or managed database proxies that drop idle TCP connections.

## Http

- Port FailOnUnknownFields form request support

## Pool

- Make `Hypervel\Pool\KeepaliveConnection` honor disabled heartbeat configuration. `PoolOption` documents `heartbeat => -1` as disabled, but `KeepaliveConnection::getHeartbeatSeconds()` currently turns any non-positive heartbeat into a 10-second interval and `addHeartbeat()` always creates a timer. Correct fix: only create the heartbeat timer when `PoolOption::getHeartbeat() > 0`; when heartbeat is `<= 0`, do not start a timer or run heartbeat work. Keep `max_idle_time` behavior separate from heartbeat.

## Routing

- Merge controller middleware from all supported sources. `Hypervel\Routing\Route::controllerMiddleware()` currently returns middleware from `HasMiddleware`, or the base controller's `getMiddleware()`, or controller attributes, but does not combine them. Laravel merges attribute middleware with the static / instance middleware path. Correct fix: port Laravel's merge behavior and add coverage for controllers that use attributes together with `HasMiddleware` or base-controller middleware.
- Align `Hypervel\Routing\Attributes\Controllers\Middleware` with Laravel's attribute API. Laravel accepts `Closure|string $middleware` and exposes the middleware value on a `$middleware` property; Hypervel currently accepts only `string $value`, and `Route::attributeProvidedControllerMiddleware()` reads `$instance->value`. Correct fix: port the Laravel constructor / property shape and update route extraction accordingly. Documentation should continue avoiding closure literals inside attributes while PHP does not allow them as attribute arguments.

## Queue

- Port `Hypervel\Contracts\Queue\PreparesForDispatch` and wire it into `Hypervel\Foundation\Bus\PendingDispatch::shouldDispatch()`. Laravel lets a job implement `prepareForDispatch()` and return `false` to abort dispatch before uniqueness locks are acquired; Hypervel currently has no contract and `PendingDispatch::shouldDispatch()` only checks `ShouldBeUnique`.
- Port queue interruption support. Laravel has `Illuminate\Contracts\Queue\Interruptible`, dispatches `WorkerInterrupted` when the worker receives `SIGQUIT`, `SIGTERM`, or `SIGINT`, and calls `interrupted($signal)` on the running queued command when it implements the contract. Hypervel's worker currently only flips `$shouldQuit` on those signals, has no `WorkerInterrupted` event, and never notifies the running command. Correct fix: add `Hypervel\Contracts\Queue\Interruptible`, port the event, track the current job/command path needed by `Worker::notifyJobOfSignal()`, dispatch the event, and call `interrupted($signal)` before the worker exits.

## Validation

- Port Rule::string() fluent string rule builder
