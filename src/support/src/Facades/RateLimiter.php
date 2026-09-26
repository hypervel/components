<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\RateLimiter\RateLimiter as RateLimiterManager;

/**
 * @method static \Hypervel\RateLimiter\RateLimiter extend(string $name, \Closure $callback)
 * @method static void flushMacros()
 * @method static void flushState()
 * @method static \Hypervel\RateLimiter\RateLimiter for(\UnitEnum|string $name, \Closure $callback, \UnitEnum|string|null $store = null)
 * @method static \Hypervel\RateLimiter\RateLimiter forgetInstance(array|string|null $name = null)
 * @method static string getDefaultInstance()
 * @method static array getInstanceConfig(string $name)
 * @method static bool hasMacro(string $name)
 * @method static mixed instance(string|null $name = null)
 * @method static \Closure|null limiter(\UnitEnum|string $name)
 * @method static string|null limiterStore(\UnitEnum|string $name)
 * @method static void macro(string $name, callable|object $macro)
 * @method static mixed macroCall(string $method, array $parameters)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static void purge(string|null $name = null)
 * @method static void resolveKeyScopeUsing(null|\Closure $resolver)
 * @method static \Hypervel\RateLimiter\RateLimiter setApplication(\Hypervel\Contracts\Foundation\Application $app)
 * @method static void setDefaultInstance(string $name)
 * @method static \Hypervel\RateLimiter\Limiter store(\UnitEnum|string|null $name = null)
 * @method static mixed attempt(\Hypervel\RateLimiter\AdmissionPolicy $policy, \Closure $callback, \UnitEnum|string|null $limiterName = null)
 * @method static \Hypervel\RateLimiter\CooldownResult block(\Hypervel\RateLimiter\Cooldown $cooldown, int $seconds, \UnitEnum|string|null $limiterName = null)
 * @method static bool clear(\Hypervel\RateLimiter\AdmissionPolicy|\Hypervel\RateLimiter\Backoff|\Hypervel\RateLimiter\Cooldown $policy, \UnitEnum|string|null $limiterName = null)
 * @method static \Hypervel\RateLimiter\LimitResult consume(\Hypervel\RateLimiter\AdmissionPolicy $policy, \UnitEnum|string|null $limiterName = null)
 * @method static array<int, \Hypervel\RateLimiter\LimitResult> consumeMany(array<int, \Hypervel\RateLimiter\AdmissionPolicy> $policies, \UnitEnum|string|null $limiterName = null)
 * @method static \Hypervel\RateLimiter\Contracts\Store getStore()
 * @method static ($policy is \Hypervel\RateLimiter\Backoff ? \Hypervel\RateLimiter\BackoffResult : ($policy is \Hypervel\RateLimiter\Cooldown ? \Hypervel\RateLimiter\CooldownResult : \Hypervel\RateLimiter\LimitResult)) inspect(\Hypervel\RateLimiter\AdmissionPolicy|\Hypervel\RateLimiter\Backoff|\Hypervel\RateLimiter\Cooldown $policy, \UnitEnum|string|null $limiterName = null)
 * @method static \Hypervel\RateLimiter\BackoffResult recordFailure(\Hypervel\RateLimiter\Backoff $backoff, \UnitEnum|string|null $limiterName = null)
 *
 * @see \Hypervel\RateLimiter\RateLimiter
 * @see \Hypervel\RateLimiter\Limiter
 */
class RateLimiter extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return RateLimiterManager::class;
    }
}
