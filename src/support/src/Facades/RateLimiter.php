<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static \Hypervel\RateLimiter\RateLimiter for(\UnitEnum|string $name, \Closure $callback, \UnitEnum|string|null $store = null)
 * @method static void resolveKeyScopeUsing(\Closure|null $resolver)
 * @method static \Closure|null limiter(\UnitEnum|string $name)
 * @method static string|null limiterStore(\UnitEnum|string $name)
 * @method static \Hypervel\RateLimiter\Limiter store(\UnitEnum|string|null $name = null)
 * @method static \Hypervel\RateLimiter\Contracts\Store getStore()
 * @method static \Hypervel\RateLimiter\LimitResult consume(\Hypervel\RateLimiter\AdmissionPolicy $policy, \UnitEnum|string|null $limiterName = null)
 * @method static \Hypervel\RateLimiter\LimitResult|\Hypervel\RateLimiter\BackoffResult inspect(\Hypervel\RateLimiter\AdmissionPolicy|\Hypervel\RateLimiter\Backoff $policy, \UnitEnum|string|null $limiterName = null)
 * @method static mixed attempt(\Hypervel\RateLimiter\AdmissionPolicy $policy, \Closure $callback, \UnitEnum|string|null $limiterName = null)
 * @method static \Hypervel\RateLimiter\BackoffResult recordFailure(\Hypervel\RateLimiter\Backoff $backoff, \UnitEnum|string|null $limiterName = null)
 * @method static bool clear(\Hypervel\RateLimiter\AdmissionPolicy|\Hypervel\RateLimiter\Backoff $policy, \UnitEnum|string|null $limiterName = null)
 * @method static string getDefaultInstance()
 * @method static void setDefaultInstance(string $name)
 * @method static array getInstanceConfig(string $name)
 * @method static \Hypervel\RateLimiter\RateLimiter forgetInstance(array|string|null $name = null)
 * @method static void purge(string|null $name = null)
 * @method static \Hypervel\RateLimiter\RateLimiter extend(string $name, \Closure $callback)
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
        return \Hypervel\RateLimiter\RateLimiter::class;
    }
}
