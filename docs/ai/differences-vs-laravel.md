# Hypervel vs Laravel — Differences

Write Hypervel apps like Laravel apps, except for these differences. Most stem from Hypervel running on Swoole coroutines: long-lived workers, no per-request bootstrap, many concurrent requests per worker process.

---

**Editing scope — read this before adding entries:**

- **Audience:** developers building Hypervel apps or third-party packages that depend on Hypervel. Assumes Laravel familiarity. Not for contributors to the Hypervel framework itself.
- **Include:** only things that *differ* from Laravel — APIs, behaviors, or patterns that don't translate 1:1.
- **Exclude:**
  - Generic advice that applies equally to Laravel (e.g. "don't hand-build a Container in tests if the code expects a booted app").
  - Internal-only Hypervel classes not shipped to userland (e.g. `Hypervel\Tests\TestCase`, which lives in the framework's `tests/` directory and isn't autoloaded by consumers).
  - Stylistic preferences and monorepo-specific conventions (e.g. frontend stack choice).
  - Hypervel features with no Laravel counterpart — those are "additions," not "differences".
- **Format:** concise bullets grouped under section headings. The doc is loaded as AI context — verbosity costs.

---

## Namespaces

- `Hypervel\` everywhere `Illuminate\` would appear in Laravel (e.g. `Hypervel\Support\Facades\Cache`, `Hypervel\Database\Eloquent\Model`, `Hypervel\Http\Request`). Ported third-party packages (e.g. Inertia) also live under `Hypervel\`.

## Runtime model

- Workers are long-lived; many requests run concurrently as coroutines inside one worker process.
- Anything on a static property or singleton service is shared across all concurrent requests in that worker — treat it like global state.

## Per-request state

- Don't use `Config::set()` for per-request or dynamic values — config is process-global and persists across requests, so anything set from a controller/middleware/job leaks to concurrent requests. Setting config from a service provider at boot is fine (runs once per worker).
- For request- or coroutine-scoped state, use `Hypervel\Context\CoroutineContext` (set/get keyed values), not static properties or service mutation.

## Container

- Unbound concrete classes are auto-singletoned on first `make()` (cached for worker lifetime). Laravel returns a fresh instance every time.
- For objects that must be fresh (mutable per-request DTOs, builders), call `$this->app->bind(Class::class)` explicitly.
- `make($abstract, [...params])` always returns fresh — parameters bypass all caching.

## Service providers

- No deferred providers. Drop `DeferrableProvider` and `provides()` when porting Laravel providers — there's no per-request bootstrap, so deferral has nothing to defer.

## Database

- Supported drivers: MySQL, MariaDB, PostgreSQL, SQLite. No SQL Server / MongoDB / DynamoDB.
- `DB::build()` and `DB::connectUsing()` are not supported (incompatible with Swoole connection pooling).

## Cache

- Supported drivers exclude Memcached, DynamoDB, MongoDB.

## Event Dispatch

- **`hasListeners()` guards skip event construction when no listeners exist.** Framework code checks `hasListeners()` before constructing event objects. If nothing is listening, the event is never created or dispatched. This is a Hypervel-specific performance optimization — Laravel always constructs and dispatches events regardless of listeners.

- **Catch-all wildcard listeners (`*`) are passive observers.** A `listen('*', ...)` registration is not counted by `hasListeners()`. When `dispatch()` is called, `*` observers still receive the event, but they are not considered "interested" listeners that justify constructing an event. Targeted wildcards (e.g. `App\Events\*`) are still counted. This prevents observability tools like Telescope's EventWatcher from defeating the `hasListeners()` guards.

## Testing

- Extend `Hypervel\Foundation\Testing\TestCase` (standard) or `Hypervel\Testbench\TestCase` (when the test writes files / needs a cloned app skeleton). Never `PHPUnit\Framework\TestCase` directly.
- Tests run inside coroutines automatically (via `RunTestsInCoroutine`, inherited from the base classes). Opt out with `protected bool $runTestsInCoroutine = false;`.
- `setUp()` / `tearDown()` run outside the test's coroutine. Use `setUpInCoroutine()` / `tearDownInCoroutine()` for code that must run inside it.
- Request and Response are coroutine-local. The `'request'` and `Hypervel\Http\Response::class` container bindings are `bind()` closures that read from `RequestContext` / `ResponseContext`. The Laravel pattern `$this->app->instance('request', $r)` (or `instance(Response::class, $r)`) doesn't apply — it overrides the closure with a worker-global value and bypasses the production resolution path. Use `RequestContext::set($r)` / `ResponseContext::set($r)` instead.
- After seeding via `RequestContext::set(...)`, `request()->merge([...])` works as in Laravel. Without seeding, each `request()` call returns a throwaway, so `merge()` is lost.
- Don't add `Mockery::close()` to `tearDown()` — handled globally.


