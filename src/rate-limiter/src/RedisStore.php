<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\RateLimiter\Contracts\Store;
use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Hypervel\Redis\RedisConnection;
use UnexpectedValueException;

class RedisStore implements Store
{
    // @TODO Re-benchmark a native INCREX implementation when equivalent bounded
    // increment-with-expiry semantics are supported by Redis and Valkey and exposed
    // by phpredis with prefix-aware Redis Cluster routing. Keep the portable Lua
    // path until then; docs/todo.md records the compatibility details.
    private const string FIXED_WINDOW_SCRIPT = <<<'LUA'
local mode = ARGV[1]
local cost = tonumber(ARGV[2])
local limit = tonumber(ARGV[3])
local durationMilliseconds = tonumber(ARGV[4])
local durationMicroseconds = durationMilliseconds * 1000

local function empty_result()
    if mode == 'inspect' then
        return {1, limit, limit, 0, 0}
    end

    redis.call('SET', KEYS[1], ARGV[2], 'PX', ARGV[4])
    return {1, limit, limit - cost, 0, durationMicroseconds}
end

local raw = redis.call('GET', KEYS[1])

if not raw then
    return empty_result()
end

if raw ~= '0' and not string.match(raw, '^[1-9]%d*$') then
    return redis.error_reply('ERR corrupt rate limiter counter')
end

local current = tonumber(raw)
if not current or current < 0 or current > limit or current % 1 ~= 0 then
    return redis.error_reply('ERR corrupt rate limiter counter')
end

local ttl = redis.call('PTTL', KEYS[1])
if ttl == -1 then
    return redis.error_reply('ERR corrupt rate limiter counter has no expiry')
end
if ttl <= 0 then
    return empty_result()
end

local ttlMicroseconds = ttl * 1000

if cost > limit - current then
    return {0, limit, limit - current, ttlMicroseconds, ttlMicroseconds}
end

if mode == 'inspect' then
    return {1, limit, limit - current, 0, ttlMicroseconds}
end

local incremented = redis.call('INCRBY', KEYS[1], ARGV[2])
return {1, limit, limit - incremented, 0, ttlMicroseconds}
LUA;

    private const string LEAKY_BUCKET_SCRIPT = <<<'LUA'
local MAX_INTEGER = 9007199254740991
local mode = ARGV[1]
local cost = tonumber(ARGV[2])
local rate = tonumber(ARGV[3])
local period = tonumber(ARGV[4])
local burst = tonumber(ARGV[5])
local time = redis.call('TIME')
local now = tonumber(time[1]) * 1000000 + tonumber(time[2])
local emission = math.floor(period / rate)

if period % rate ~= 0 then
    emission = emission + 1
end

local burstDuration = emission * burst
local costDuration = emission * cost
local storedTat = now
local raw = redis.call('GET', KEYS[1])

if raw then
    if raw ~= '0' and not string.match(raw, '^[1-9]%d*$') then
        return redis.error_reply('ERR corrupt rate limiter TAT')
    end

    storedTat = tonumber(raw)
    if not storedTat or storedTat < 0 or storedTat > MAX_INTEGER or storedTat % 1 ~= 0 then
        return redis.error_reply('ERR corrupt rate limiter TAT')
    end

    local ttl = redis.call('PTTL', KEYS[1])
    if ttl == -1 then
        return redis.error_reply('ERR corrupt rate limiter TAT has no expiry')
    end
    if ttl <= 0 then
        storedTat = now
    end
end

local effectiveTat = math.max(storedTat, now)
if effectiveTat > MAX_INTEGER - costDuration then
    return redis.error_reply('ERR rate limiter TAT overflow')
end

local candidateTat = effectiveTat + costDuration
local allowedAt = candidateTat - burstDuration
local allowed = now >= allowedAt

if now > MAX_INTEGER - burstDuration then
    return redis.error_reply('ERR rate limiter capacity overflow')
end

local remaining = math.floor((now + burstDuration - effectiveTat) / emission)
remaining = math.max(0, math.min(burst, remaining))
local reset = math.max(effectiveTat - now, 0)

if not allowed then
    return {0, burst, remaining, allowedAt - now, reset}
end

if mode == 'inspect' then
    return {1, burst, remaining, 0, reset}
end

local nextRemaining = math.floor((now + burstDuration - candidateTat) / emission)
nextRemaining = math.max(0, math.min(burst, nextRemaining))
local nextReset = candidateTat - now
local ttl = math.max(1, math.floor((nextReset + 999) / 1000))

redis.call('SET', KEYS[1], candidateTat, 'PX', ttl)

return {1, burst, nextRemaining, 0, nextReset}
LUA;

    private const string BACKOFF_SCRIPT = <<<'LUA'
local MAX_INTEGER = 9007199254740991
local mode = ARGV[1]
local after = tonumber(ARGV[2])
local initialDelay = tonumber(ARGV[3])
local maxDelay = tonumber(ARGV[4])
local resetAfter = tonumber(ARGV[5])
local time = redis.call('TIME')
local now = tonumber(time[1]) * 1000000 + tonumber(time[2])
local failures = 0
local availableAt = 0
local state = redis.call('HMGET', KEYS[1], 'failures', 'available_at')

if state[1] or state[2] then
    if not state[1] or not state[2] then
        return redis.error_reply('ERR corrupt rate limiter backoff state')
    end

    for _, raw in ipairs(state) do
        if raw ~= '0' and not string.match(raw, '^[1-9]%d*$') then
            return redis.error_reply('ERR corrupt rate limiter backoff state')
        end
    end

    failures = tonumber(state[1])
    availableAt = tonumber(state[2])

    if not failures or failures < 0 or failures > MAX_INTEGER or failures % 1 ~= 0
        or not availableAt or availableAt < 0 or availableAt > MAX_INTEGER or availableAt % 1 ~= 0 then
        return redis.error_reply('ERR corrupt rate limiter backoff state')
    end

    local ttl = redis.call('PTTL', KEYS[1])
    if ttl == -1 then
        return redis.error_reply('ERR corrupt rate limiter backoff state has no expiry')
    end
    if ttl <= 0 then
        failures = 0
        availableAt = 0
    elseif failures == 0 then
        return redis.error_reply('ERR corrupt rate limiter backoff state')
    end
end

if mode == 'inspect' then
    local retry = math.max(availableAt - now, 0)
    return {retry == 0 and 1 or 0, failures, 0, retry, 0}
end

if failures >= MAX_INTEGER then
    return redis.error_reply('ERR rate limiter failure count overflow')
end

failures = failures + 1
local delay = 0

if failures >= after then
    delay = initialDelay
    local doublings = failures - after

    while doublings > 0 and delay < maxDelay do
        if delay > math.floor(maxDelay / 2) then
            delay = maxDelay
        else
            delay = math.min(delay * 2, maxDelay)
        end

        doublings = doublings - 1
    end
end

if now > MAX_INTEGER - resetAfter or now > MAX_INTEGER - delay then
    return redis.error_reply('ERR rate limiter backoff timestamp overflow')
end

availableAt = delay == 0 and 0 or now + delay

redis.call('HSET', KEYS[1], 'failures', failures, 'available_at', availableAt)
redis.call('PEXPIRE', KEYS[1], math.max(1, math.floor((resetAfter + 999) / 1000)))

return {delay == 0 and 1 or 0, failures, 0, delay, 0}
LUA;

    /**
     * Create a new Redis rate limiter store.
     */
    public function __construct(
        protected RedisFactory $redis,
        protected string $connection,
    ) {
    }

    /**
     * Atomically consume capacity from an admission policy.
     */
    public function consume(string $key, AdmissionPolicy $policy): LimitResult
    {
        return match (true) {
            $policy instanceof Limit => $this->executeFixedWindow($key, $policy, 'consume'),
            $policy instanceof LeakyBucket => $this->executeLeakyBucket($key, $policy, 'consume'),
            default => throw new InvalidRateLimitException(sprintf(
                'Admission policy [%s] is not supported.',
                $policy::class,
            )),
        };
    }

    /**
     * Inspect a policy without mutating its state.
     *
     * @return ($policy is Backoff ? BackoffResult : LimitResult)
     */
    public function inspect(string $key, AdmissionPolicy|Backoff $policy): LimitResult|BackoffResult
    {
        return match (true) {
            $policy instanceof Limit => $this->executeFixedWindow($key, $policy, 'inspect'),
            $policy instanceof LeakyBucket => $this->executeLeakyBucket($key, $policy, 'inspect'),
            $policy instanceof Backoff => $this->executeBackoff($key, $policy, 'inspect'),
            default => throw new InvalidRateLimitException(sprintf(
                'Admission policy [%s] is not supported.',
                $policy::class,
            )),
        };
    }

    /**
     * Record a failure against a backoff policy.
     */
    public function recordFailure(string $key, Backoff $backoff): BackoffResult
    {
        return $this->executeBackoff($key, $backoff, 'failure');
    }

    /**
     * Clear the state for a physical limiter key.
     */
    public function clear(string $key): bool
    {
        return $this->redis->connection($this->connection)->withConnection(
            static fn (RedisConnection $connection): bool => (int) $connection->del($key) > 0,
            transform: false,
        );
    }

    /**
     * Execute a fixed-window operation.
     */
    protected function executeFixedWindow(string $key, Limit $policy, string $mode): LimitResult
    {
        $result = $this->execute(self::FIXED_WINDOW_SCRIPT, $key, [
            $mode,
            (string) $policy->cost,
            (string) $policy->maxAttempts,
            (string) ($policy->decaySeconds * 1000),
        ]);

        return $this->limitResult($result, $policy->maxAttempts);
    }

    /**
     * Execute a leaky-bucket operation.
     */
    protected function executeLeakyBucket(string $key, LeakyBucket $policy, string $mode): LimitResult
    {
        $result = $this->execute(self::LEAKY_BUCKET_SCRIPT, $key, [
            $mode,
            (string) $policy->cost,
            (string) $policy->rate,
            (string) $policy->periodMicroseconds,
            (string) $policy->burst,
        ]);

        return $this->limitResult($result, $policy->burst);
    }

    /**
     * Execute an exponential-backoff operation.
     */
    protected function executeBackoff(string $key, Backoff $backoff, string $mode): BackoffResult
    {
        $result = $this->execute(self::BACKOFF_SCRIPT, $key, [
            $mode,
            (string) $backoff->after,
            (string) ($backoff->initialDelay * 1_000_000),
            (string) ($backoff->maxDelay * 1_000_000),
            (string) ($backoff->resetAfter * 1_000_000),
        ]);
        $values = $this->integerTuple($result);

        if ($values[1] < 0 || $values[1] > AdmissionPolicy::MAX_INTEGER
            || $values[2] !== 0 || $values[4] !== 0) {
            throw new UnexpectedValueException('Redis returned an invalid rate limiter backoff result.');
        }

        return new BackoffResult($values[0] === 1, $values[1], $values[3]);
    }

    /**
     * Execute a rate limiter script through Redis's SHA cache.
     */
    protected function execute(string $script, string $key, array $arguments): mixed
    {
        return $this->redis->connection($this->connection)->withConnection(
            static fn (RedisConnection $connection): mixed => $connection->evalWithShaCache(
                $script,
                [$key],
                $arguments,
            ),
            transform: false,
        );
    }

    /**
     * Convert a Redis tuple to an admission result.
     */
    protected function limitResult(mixed $result, int $expectedLimit): LimitResult
    {
        $values = $this->integerTuple($result);

        if ($values[1] !== $expectedLimit
            || $values[2] < 0 || $values[2] > $expectedLimit) {
            throw new UnexpectedValueException('Redis returned an invalid rate limiter admission result.');
        }

        return new LimitResult(
            $values[0] === 1,
            $values[1],
            $values[2],
            $values[3],
            $values[4],
        );
    }

    /**
     * Validate and return a five-integer Redis result tuple.
     *
     * @return array{int, int, int, int, int}
     */
    protected function integerTuple(mixed $result): array
    {
        if (! is_array($result) || ! array_is_list($result) || count($result) !== 5) {
            throw new UnexpectedValueException('Redis returned a malformed rate limiter result.');
        }

        foreach ($result as $value) {
            if (! is_int($value) || $value < 0 || $value > AdmissionPolicy::MAX_INTEGER) {
                throw new UnexpectedValueException('Redis returned a malformed rate limiter result.');
            }
        }

        if ($result[0] !== 0 && $result[0] !== 1) {
            throw new UnexpectedValueException('Redis returned an invalid rate limiter decision flag.');
        }

        /** @var array{int, int, int, int, int} $result */
        return $result;
    }
}
