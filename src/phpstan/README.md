PHPStan for Hypervel
===

Documentation: https://hypervel.org/docs/static-analysis

## Differences From Larastan

- Package-owned diagnostic identifiers use the `hypervel.*` prefix instead of `larastan.*` (and `rules.modelAppends`). PHPStan's own identifiers are unchanged.
- Several checks are enabled by default, and Hypervel adds coroutine, worker-lifetime and authoring-policy checks. See the [configuration defaults](https://hypervel.org/docs/static-analysis#configuration).
- There is no Octane compatibility rule or `checkOctaneCompatibility` setting; Hypervel's worker-lifetime checks replace it. There is no deferred service provider rule, because Hypervel has no deferred providers.
- Container ArrayAccess return types are not provided, because the Hypervel container does not support ArrayAccess.
- A value typed as an interface does not gain methods or macros from its implementations. Upstream resolves an undefined method or a macro on a contract against its container binding, and on the auth user contracts against the configured auth models. Explicit resolution, such as `app(Repository::class)` or the factory returned by `response()`, is typed from the booted application's binding instead.
- Without a guard name, `Auth::guard()` and `auth()->guard()` may return any configured guard, because `shouldUse()` changes the default guard per request. A guard whose type cannot be read from its driver keeps the declared `Guard` type rather than `StatefulGuard`.
- The request-scope auth rules offer no automatic fix, because the request's user resolver can return a different user than the `Auth` facade or `auth()` helper.
- Collections keep Laravel's covariant value type, so a `Collection<int, User>` is accepted where a `Collection<int, Model>` is expected. Upstream's stubs make it invariant. With bleeding edge, PHPStan does not widen a covariant value type it has already inferred, so a call such as `collect([1, 2, 3])->push(4)` is reported. See [Collection Types](https://hypervel.org/docs/static-analysis#collection-types).
- `now()`, `today()` and the `Date` facade return `CarbonInterface`, as declared, rather than the date class configured when analysis runs. Code that needs a specific class should convert the value, for example with `toImmutable()`.
- The `generalizeEnvReturnType` and `redisDriver` settings are omitted. An `env()` default does not decide the type of the value it reads, and Redis types always describe Hypervel's phpredis connection.
- Request input getters return the types the input can hold. A keyed `query()`, `post()` or `cookie()` value can be a string, number, boolean, array or `Stringable`, a keyed `server()` or `route()` value is `mixed`, and `header()` can return null even with a default. Whole query, post, cookie and file arrays have `array-key` keys, because a query string or JSON list body can use integer keys. Upstream's narrower types do not cover these values. `FormRequest::validated()` and `safe($keys)` return `array<array-key, mixed>` for the same reason.
- `Storage::disk()` and `FilesystemManager::disk()` are typed per disk from the booted configuration, such as `ClientPooledFilesystem` for a pooled S3 disk, instead of always `FilesystemAdapter`. Calls the `Storage` facade forwards to the default disk use that disk's methods. `Storage::cloud()` is not supported.
- Connection-bound Redis methods such as `client()` and `auth()` are reported when called through `Redis::connection()` or the manager, because the pooled proxy rejects them. Call them on a held connection from `Redis::withConnection()`.

Ported from: https://github.com/larastan/larastan
