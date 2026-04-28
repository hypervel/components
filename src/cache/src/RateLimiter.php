<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Support\Collection;
use Hypervel\Support\InteractsWithTime;
use UnitEnum;

use function Hypervel\Support\enum_value;

class RateLimiter
{
    use InteractsWithTime;

    /**
     * The cache store implementation.
     */
    protected Cache $cache;

    /**
     * The configured limit object resolvers.
     */
    protected array $limiters = [];

    /**
     * Create a new rate limiter instance.
     */
    public function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Register a named limiter configuration.
     */
    public function for(UnitEnum|string $name, Closure $callback): static
    {
        $resolvedName = $this->resolveLimiterName($name);

        $this->limiters[$resolvedName] = $callback;

        return $this;
    }

    /**
     * Get the given named rate limiter.
     */
    public function limiter(UnitEnum|string $name): ?Closure
    {
        $resolvedName = $this->resolveLimiterName($name);

        $limiter = $this->limiters[$resolvedName] ?? null;

        if (! is_callable($limiter)) {
            return null;
        }

        return function (...$args) use ($limiter) {
            $result = $limiter(...$args);

            if (! is_array($result)) {
                return $result;
            }

            $duplicates = (new Collection($result))->duplicates('key');

            if ($duplicates->isEmpty()) {
                return $result;
            }

            foreach ($result as $limit) {
                if ($duplicates->contains($limit->key)) {
                    $limit->key = $limit->fallbackKey();
                }
            }

            return $result;
        };
    }

    /**
     * Attempt to execute a callback if it's not limited.
     */
    public function attempt(string $key, int $maxAttempts, Closure $callback, DateInterval|DateTimeInterface|int $decaySeconds = 60): mixed
    {
        if ($this->tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        if (is_null($result = $callback())) {
            $result = true;
        }

        return tap($result, function () use ($key, $decaySeconds) {
            $this->hit($key, $decaySeconds);
        });
    }

    /**
     * Determine if the given key has been "accessed" too many times.
     */
    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        if ($this->attempts($key) >= $maxAttempts) {
            if ($this->cache->has($this->cleanRateLimiterKey($key) . ':timer')) {
                return true;
            }

            $this->resetAttempts($key);
        }

        return false;
    }

    /**
     * Increment (by 1) the counter for a given key for a given decay time.
     */
    public function hit(string $key, DateInterval|DateTimeInterface|int $decaySeconds = 60): int
    {
        return $this->increment($key, $decaySeconds);
    }

    /**
     * Increment the counter for a given key for a given decay time by a given amount.
     */
    public function increment(string $key, DateInterval|DateTimeInterface|int $decaySeconds = 60, int $amount = 1): int
    {
        $key = $this->cleanRateLimiterKey($key);

        $this->cache->add(
            $key . ':timer',
            $this->availableAt($decaySeconds),
            $decaySeconds
        );

        $added = $this->withoutSerializationOrCompression(
            fn () => $this->cache->add($key, 0, $decaySeconds)
        );

        $hits = (int) $this->cache->increment($key, $amount);

        if (! $added && $hits === 1) {
            $this->withoutSerializationOrCompression(
                fn () => $this->cache->put($key, 1, $decaySeconds)
            );
        }

        return $hits;
    }

    /**
     * Decrement the counter for a given key for a given decay time by a given amount.
     */
    public function decrement(string $key, DateInterval|DateTimeInterface|int $decaySeconds = 60, int $amount = 1): int
    {
        return $this->increment($key, $decaySeconds, $amount * -1);
    }

    /**
     * Get the number of attempts for the given key.
     */
    public function attempts(string $key): mixed
    {
        $key = $this->cleanRateLimiterKey($key);

        return $this->withoutSerializationOrCompression(fn () => $this->cache->get($key, 0));
    }

    /**
     * Reset the number of attempts for the given key.
     */
    public function resetAttempts(string $key): bool
    {
        $key = $this->cleanRateLimiterKey($key);

        return $this->cache->forget($key);
    }

    /**
     * Get the number of retries left for the given key.
     */
    public function remaining(string $key, int $maxAttempts): int
    {
        $key = $this->cleanRateLimiterKey($key);

        $attempts = $this->attempts($key);

        return max(0, $maxAttempts - $attempts);
    }

    /**
     * Get the number of retries left for the given key.
     */
    public function retriesLeft(string $key, int $maxAttempts): int
    {
        return $this->remaining($key, $maxAttempts);
    }

    /**
     * Clear the hits and lockout timer for the given key.
     */
    public function clear(string $key): void
    {
        $key = $this->cleanRateLimiterKey($key);

        $this->resetAttempts($key);

        $this->cache->forget($key . ':timer');
    }

    /**
     * Get the number of seconds until the "key" is accessible again.
     */
    public function availableIn(string $key): int
    {
        $key = $this->cleanRateLimiterKey($key);

        return max(0, $this->cache->get($key . ':timer') - $this->currentTime());
    }

    /**
     * Clean the rate limiter key from unicode characters.
     */
    public function cleanRateLimiterKey(string $key): string
    {
        return preg_replace('/&([a-z])[a-z]+;/i', '$1', htmlentities($key));
    }

    /**
     * Execute the given callback without serialization or compression when applicable.
     */
    protected function withoutSerializationOrCompression(callable $callback): mixed
    {
        $store = $this->cache->getStore();

        if (! $store instanceof RedisStore) {
            return $callback();
        }

        return $store->connection()->withoutSerializationOrCompression($callback);
    }

    /**
     * Resolve the rate limiter name.
     */
    private function resolveLimiterName(UnitEnum|string $name): string
    {
        return (string) enum_value($name);
    }
}
