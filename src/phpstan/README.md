PHPStan for Hypervel
===

Documentation: https://hypervel.org/docs/phpstan

## Differences From Larastan

- Package-owned diagnostic identifiers use the `hypervel.*` prefix instead of `larastan.*` (and `rules.modelAppends`). PHPStan's own identifiers are unchanged.
- Several checks are enabled by default, and Hypervel adds coroutine, worker-lifetime and authoring-policy checks. See the [configuration defaults](https://hypervel.org/docs/phpstan#configuration).
- There is no Octane compatibility rule or `checkOctaneCompatibility` setting; Hypervel's worker-lifetime checks replace it. There is no deferred service provider rule, because Hypervel has no deferred providers.
- Container ArrayAccess return types are not provided, because the Hypervel container does not support ArrayAccess.
- The `generalizeEnvReturnType` and `redisDriver` settings are omitted. An `env()` default does not decide the type of the value it reads, and Redis types always describe Hypervel's phpredis connection.

Ported from: https://github.com/larastan/larastan
