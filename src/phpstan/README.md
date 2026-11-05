PHPStan for Hypervel
===

Documentation: https://hypervel.org/docs/static-analysis

## Differences From Larastan

- Package-owned diagnostic identifiers use the `hypervel.*` prefix instead of `larastan.*` (and `rules.modelAppends`). PHPStan's own identifiers are unchanged.
- Several checks are enabled by default, and Hypervel adds coroutine, worker-lifetime and authoring-policy checks. See the [configuration defaults](https://hypervel.org/docs/static-analysis#configuration).
- There is no Octane compatibility rule or `checkOctaneCompatibility` setting; Hypervel's worker-lifetime checks replace it. There is no deferred service provider rule, because Hypervel has no deferred providers.
- Container ArrayAccess return types are not provided, because the Hypervel container does not support ArrayAccess.
- A value typed as an interface does not gain methods from its implementations. Upstream resolves an undefined method on a contract against its container binding, and on the auth user contracts against the configured auth models. Explicit container resolution, such as `app(Repository::class)`, is still typed from the booted application's binding.
- Without a guard name, `Auth::guard()` and `auth()->guard()` may return any configured guard, because `shouldUse()` changes the default guard per request. A guard whose type cannot be read from its driver keeps the declared `Guard` type rather than `StatefulGuard`.
- The request-scope auth rules offer no automatic fix, because the request's user resolver can return a different user than the `Auth` facade or `auth()` helper.
- `now()`, `today()` and the `Date` facade return `CarbonInterface`, as declared, rather than the date class configured when analysis runs. Code that needs a specific class should convert the value, for example with `toImmutable()`.
- The `generalizeEnvReturnType` and `redisDriver` settings are omitted. An `env()` default does not decide the type of the value it reads, and Redis types always describe Hypervel's phpredis connection.

Ported from: https://github.com/larastan/larastan
