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
- Routes retain caller-supplied closures, invokable objects, and object-method callables for the worker lifetime. Prefer `[Controller::class, 'method']` when the container should control the controller's singleton, scoped, or transient lifetime.

## Per-request state

- Don't use `Config::set()` for per-request or dynamic values — config is process-global and persists across requests, so anything set from a controller/middleware/job leaks to concurrent requests. Setting config from a service provider at boot is fine (runs once per worker).
- For request- or coroutine-scoped state, use `Hypervel\Context\CoroutineContext` (set/get keyed values), not static properties or service mutation.

## Container

Hypervel caches more aggressively than Laravel: any class resolved via `make()` becomes a worker-lifetime singleton unless bound otherwise, in which case `make()` respects that binding.

| Need | Call | Lifecycle |
|---|---|---|
| Always fresh, bypass bindings and caching | `$app->build($class)` / `$app->buildWith($class, $params)` | Constructs the given concrete directly; nested dependencies still resolve through the container |
| Class-controlled construction | `implements SelfBuilding` + static `newInstance()` | Container calls `newInstance` per `make()`; skips auto-singleton; honors explicit `singleton()` / `scoped()` if applied |
| Default resolution | `$app->make($class)` | Auto-singleton if unbound |
| Fresh with parameter overrides | `$app->make($class, $params)` / `makeWith()` | Not cached (contextual build) |
| Worker-wide singleton | `singleton(...)` or `#[Singleton]` | Worker lifetime |
| Per-request singleton | `scoped(...)` or `#[Scoped]` | `CoroutineContext`, per coroutine |
| Fresh every `make()` | `bind(...)` | Not cached |
| Pre-built object | `instance($abstract, $obj)` | Returns the exact passed object |

**Footguns:**

- Don't capture per-call state (e.g. `Request` data) in `__construct` of an auto-resolved class — the first resolution freezes it for all subsequent requests on that worker. Fixes: `bind()` the class, call `build()` at the resolution site, or implement `SelfBuilding` with a `newInstance()` factory.
- Don't use `build()` as a drop-in fresh replacement for `make()` when explicit bindings, aliases, test swaps, or resolving callbacks must be honored. It skips top-level container lifecycle machinery by design.
- Don't mutate `$this->foo` on a worker-lifetime singleton during request handling. The mutation persists across every request that worker handles. Use `CoroutineContext` for per-request state on shared services.

## Service providers

- No deferred providers. Drop `DeferrableProvider` and `provides()` when porting Laravel providers — there's no per-request bootstrap, so deferral has nothing to defer.

## Database

- Supported drivers: MySQL, MariaDB, PostgreSQL, SQLite. No SQL Server / MongoDB / DynamoDB.
- `DB::build()` and `DB::connectUsing()` are not supported (incompatible with Swoole connection pooling).

## Cache

- Supported drivers exclude Memcached, DynamoDB, MongoDB.

## Dates

- Hypervel's `Date` facade, `now()` / `today()` helpers, and ordinary Eloquent date casts return `Hypervel\Support\CarbonImmutable` by default. Assign date-modifier results (`$date = $date->addDay()`) when retaining the changed value. Applications that deliberately need Laravel's mutable default may configure `Date::use(Hypervel\Support\Carbon::class)` during boot.

## Event Dispatch

- **`hasListeners()` guards skip event construction when no listeners exist.** Framework code checks `hasListeners()` before constructing event objects. If nothing is listening, the event is never created or dispatched. This is a Hypervel-specific performance optimization — Laravel always constructs and dispatches events regardless of listeners.

- **Catch-all wildcard listeners (`*`) are passive observers.** A `listen('*', ...)` registration is not counted by `hasListeners()`. When `dispatch()` is called, `*` observers still receive the event, but they are not considered "interested" listeners that justify constructing an event. Targeted wildcards (e.g. `App\Events\*`) are still counted. This prevents observability tools like Telescope's EventWatcher from defeating the `hasListeners()` guards.

## Testing

- Extend `Hypervel\Foundation\Testing\TestCase` (standard) or `Hypervel\Testbench\TestCase` (when the test writes files / needs a cloned app skeleton). Never `PHPUnit\Framework\TestCase` directly.
- Tests run inside coroutines automatically (via `RunTestsInCoroutine`, inherited from the base classes). Opt out with `protected bool $runTestsInCoroutine = false;`.
- `setUp()` / `tearDown()` run outside the test's coroutine. Use `setUpInCoroutine()` / `tearDownInCoroutine()` for code that must run inside it.
- Request and Response are coroutine-local. The `'request'` and `Hypervel\Http\Response::class` container bindings are `bind()` closures that read from `RequestContext` / `ResponseContext`. The Laravel pattern `$this->app->instance('request', $r)` (or `instance(Response::class, $r)`) doesn't apply — it overrides the closure with a worker-global value and bypasses the production resolution path. Use `RequestContext::set($r)` / `ResponseContext::set($r)` instead.
- After seeding via `RequestContext::set(...)`, `request()->merge([...])` works as in Laravel. Without seeding, each `request()` call returns a throwaway, so `merge()` is lost.
