<?php

declare(strict_types=1);

namespace Hypervel\Routing\Middleware;

use Closure;
use Hypervel\Cache\RateLimiter;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Http\Request;
use Hypervel\Redis\Limiters\DurationLimiter;
use Hypervel\Redis\RedisProxy;
use Symfony\Component\HttpFoundation\Response;

class ThrottleRequestsWithRedis extends ThrottleRequests
{
    /**
     * The Redis factory implementation.
     */
    protected Redis $redis;

    /**
     * The timestamp of the end of the current duration by key.
     */
    public array $decaysAt = [];

    /**
     * The number of remaining slots by key.
     */
    public array $remaining = [];

    /**
     * Create a new request throttler.
     */
    public function __construct(RateLimiter $limiter, Redis $redis)
    {
        parent::__construct($limiter);

        $this->redis = $redis;
    }

    /**
     * Handle an incoming request.
     *
     * @throws \Hypervel\Http\Exceptions\ThrottleRequestsException
     */
    protected function handleRequest(Request $request, Closure $next, array $limits): Response
    {
        foreach ($limits as $limit) {
            if ($this->tooManyAttempts($limit->key, $limit->maxAttempts, $limit->decaySeconds)) {
                throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
            }
        }

        $response = $next($request);

        foreach ($limits as $limit) {
            $response = $this->addHeaders(
                $response,
                $limit->maxAttempts,
                $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts)
            );
        }

        return $response;
    }

    /**
     * Determine if the given key has been "accessed" too many times.
     */
    protected function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $limiter = new DurationLimiter(
            $this->getRedisConnection(),
            $key,
            $maxAttempts,
            $decaySeconds
        );

        return tap(! $limiter->acquire(), function () use ($key, $limiter) {
            [$this->decaysAt[$key], $this->remaining[$key]] = [
                $limiter->decaysAt, $limiter->remaining,
            ];
        });
    }

    /**
     * Calculate the number of remaining attempts.
     */
    protected function calculateRemainingAttempts(string $key, int $maxAttempts, ?int $retryAfter = null): int
    {
        return is_null($retryAfter) ? $this->remaining[$key] : 0;
    }

    /**
     * Get the number of seconds until the next retry.
     */
    protected function getTimeUntilNextRetry(string $key): int
    {
        return $this->decaysAt[$key] - $this->currentTime();
    }

    /**
     * Get the Redis connection that should be used for throttling.
     */
    protected function getRedisConnection(): RedisProxy
    {
        return $this->redis->connection();
    }
}
