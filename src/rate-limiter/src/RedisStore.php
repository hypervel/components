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
    private const string FIXED_WINDOW_FUNCTIONS = <<<'LUA'
local function load_fixed(key, policy)
    local raw = redis.call('GET', key)
    if not raw then
        return {current = 0, ttl = 0, fresh = true}
    end
    if raw ~= '0' and not string.match(raw, '^[1-9]%d*$') then
        error('corrupt rate limiter counter', 0)
    end
    local current = tonumber(raw)
    if current > policy[2] then
        error('corrupt rate limiter counter', 0)
    end
    local ttl = redis.call('PTTL', key)
    if ttl == -1 then
        error('corrupt rate limiter counter has no expiry', 0)
    end
    if ttl <= 0 then
        return {current = 0, ttl = 0, fresh = true}
    end
    return {current = current, original = current, ttl = ttl, fresh = false}
end

local function calculate_fixed(state, policy, consume)
    local cost, limit, duration = unpack(policy)
    if cost > limit - state.current then
        return {0, limit, limit - state.current, state.ttl * 1000, state.ttl * 1000}
    end
    if consume then
        state.current = state.current + cost
        if state.fresh then
            state.ttl = duration
        end
    end
    return {1, limit, limit - state.current, 0, state.ttl * 1000}
end

local function persist_fixed(key, state)
    if state.fresh then
        redis.call('SET', key, state.current, 'PX', state.ttl)
    else
        redis.call('INCRBY', key, state.current - state.original)
    end
end
LUA;

    private const string SLIDING_WINDOW_FUNCTIONS = <<<'LUA'
local function load_sliding(key, policy)
    local limit, windowSeconds = policy[2], policy[3]
    local windowMilliseconds = windowSeconds * 1000
    local values = redis.call('HMGET', key, 'current', 'previous')
    local function empty_state()
        return {current = 0, previous = 0, ttl = 0, remaining = windowMilliseconds, fresh = true}
    end
    if not values[1] and not values[2] then
        return empty_state()
    end
    if not values[1] or not values[2] then
        error('corrupt rate limiter sliding-window state', 0)
    end
    for _, raw in ipairs(values) do
        if raw ~= '0' and not string.match(raw, '^[1-9]%d*$') then
            error('corrupt rate limiter sliding-window state', 0)
        end
    end
    local current, previous = tonumber(values[1]), tonumber(values[2])
    if current > limit or previous > limit then
        error('corrupt rate limiter sliding-window state', 0)
    end
    local ttl = redis.call('PTTL', key)
    if ttl == -1 then
        error('corrupt rate limiter sliding-window state has no expiry', 0)
    end
    if ttl <= 0 then
        return empty_state()
    end
    if current == 0 then
        error('corrupt rate limiter sliding-window state', 0)
    end
    if ttl > windowMilliseconds then
        return {current = current, original = current, previous = previous,
            ttl = ttl, remaining = ttl - windowMilliseconds}
    end
    return {current = 0, previous = current, ttl = ttl, remaining = ttl, rotated = true}
end

local function calculate_sliding(state, policy, consume)
    local WEIGHT_SCALE = 1000000
    local cost, limit, windowSeconds = unpack(policy)
    local windowMilliseconds = windowSeconds * 1000
    local weight = WEIGHT_SCALE
    if state.remaining < windowMilliseconds then
        weight = math.floor(state.remaining * 1000 / windowSeconds)
    end
    local estimated = state.current + math.floor(state.previous * weight / WEIGHT_SCALE)
    if estimated > limit - cost then
        local available = limit - state.current - cost
        local retryMicroseconds
        if available >= 0 then
            local maximumWeight = math.floor(((available + 1) * WEIGHT_SCALE - 1) / state.previous)
            local maximumRemainingMilliseconds = math.floor(((maximumWeight + 1) * windowSeconds - 1) / 1000)
            retryMicroseconds = (state.remaining - maximumRemainingMilliseconds) * 1000
        else
            local nextAvailable = limit - cost
            local maximumWeight = math.floor(((nextAvailable + 1) * WEIGHT_SCALE - 1) / state.current)
            local maximumRemainingMilliseconds = math.floor(((maximumWeight + 1) * windowSeconds - 1) / 1000)
            retryMicroseconds = state.remaining * 1000 + (windowMilliseconds - maximumRemainingMilliseconds) * 1000
        end
        return {0, limit, math.max(0, limit - estimated), retryMicroseconds, state.ttl * 1000}
    end
    if consume then
        state.current = state.current + cost
        estimated = estimated + cost
        if state.fresh or state.rotated then
            state.ttl = state.remaining + windowMilliseconds
        end
    end
    return {1, limit, limit - estimated, 0, state.ttl * 1000}
end

local function persist_sliding(key, state)
    if state.fresh or state.rotated then
        redis.call('HSET', key, 'current', state.current, 'previous', state.previous)
        redis.call('PEXPIRE', key, state.ttl)
    else
        redis.call('HINCRBY', key, 'current', state.current - state.original)
    end
end
LUA;

    private const string LEAKY_BUCKET_FUNCTIONS = <<<'LUA'
local function load_leaky(key, policy, now)
    local storedTat = now
    local raw = redis.call('GET', key)
    if raw then
        if raw ~= '0' and not string.match(raw, '^[1-9]%d*$') then
            error('corrupt rate limiter TAT', 0)
        end
        storedTat = tonumber(raw)
        if storedTat > 9007199254740991 then
            error('corrupt rate limiter TAT', 0)
        end
        local ttl = redis.call('PTTL', key)
        if ttl == -1 then
            error('corrupt rate limiter TAT has no expiry', 0)
        end
        if ttl <= 0 then
            storedTat = now
        end
    end
    return {tat = math.max(storedTat, now), now = now}
end

local function calculate_leaky(state, policy, consume)
    local MAX_INTEGER = 9007199254740991
    local cost, rate, period, burst = unpack(policy)
    local emission = math.floor(period / rate)
    if period % rate ~= 0 then
        emission = emission + 1
    end
    local burstDuration = emission * burst
    local costDuration = emission * cost
    if state.tat > MAX_INTEGER - costDuration then
        error('rate limiter TAT overflow', 0)
    end
    if state.now > MAX_INTEGER - burstDuration then
        error('rate limiter capacity overflow', 0)
    end
    local candidateTat = state.tat + costDuration
    local allowedAt = candidateTat - burstDuration
    local remaining = math.floor((state.now + burstDuration - state.tat) / emission)
    remaining = math.max(0, math.min(burst, remaining))
    if state.now < allowedAt then
        return {0, burst, remaining, allowedAt - state.now, state.tat - state.now}
    end
    if consume then
        state.tat = candidateTat
        remaining = math.floor((state.now + burstDuration - candidateTat) / emission)
        remaining = math.max(0, math.min(burst, remaining))
    end
    return {1, burst, remaining, 0, state.tat - state.now}
end

local function persist_leaky(key, state)
    local ttl = math.max(1, math.floor((state.tat - state.now + 999) / 1000))
    redis.call('SET', key, state.tat, 'PX', ttl)
end
LUA;

    private const string CONSUME_MANY_SCRIPT = self::FIXED_WINDOW_FUNCTIONS . "\n"
        . self::SLIDING_WINDOW_FUNCTIONS . "\n" . self::LEAKY_BUCKET_FUNCTIONS . <<<'LUA'

local algorithms = {
    fixed = {load_fixed, calculate_fixed, persist_fixed},
    sliding = {load_sliding, calculate_sliding, persist_sliding},
    leaky = {load_leaky, calculate_leaky, persist_leaky}
}
local states, originals, policies, results = {}, {}, {}, {}
local now
for index, key in ipairs(KEYS) do
    local offset = (index - 1) * 5
    local kind = ARGV[offset + 1]
    local algorithm = algorithms[kind]
    local policy = {tonumber(ARGV[offset + 2]), tonumber(ARGV[offset + 3]),
        tonumber(ARGV[offset + 4]), tonumber(ARGV[offset + 5])}
    policies[index] = {algorithm, policy}
    if not states[key] then
        if kind == 'leaky' and not now then
            local time = redis.call('TIME')
            now = tonumber(time[1]) * 1000000 + tonumber(time[2])
        end
        local state = algorithm[1](key, policy, now)
        originals[key] = {}
        for field, value in pairs(state) do
            originals[key][field] = value
        end
        states[key] = state
    end
    results[index] = algorithm[2](states[key], policy, true)
    if results[index][1] == 0 then
        -- No writes have happened. Report capacity without the discarded charges.
        for position = 1, index do
            local entry = policies[position]
            local inspection = entry[1][2](originals[KEYS[position]], entry[2], false)
            if position == index then
                results[position][3] = inspection[3]
            else
                results[position] = inspection
            end
        end
        return results
    end
end
for index, key in ipairs(KEYS) do
    if states[key] then
        policies[index][1][3](key, states[key])
        states[key] = nil
    end
end
return results
LUA;

    private const string SCALAR_ADMISSION_SCRIPT = <<<'LUA'
local policy = {tonumber(ARGV[2]), tonumber(ARGV[3]), tonumber(ARGV[4]), tonumber(ARGV[5])}
local state = load(KEYS[1], policy, now)
local consume = ARGV[1] == 'consume'
local result = calculate(state, policy, consume)
if consume and result[1] == 1 then
    persist(KEYS[1], state)
end
return result
LUA;

    private const string FIXED_WINDOW_SCRIPT = self::FIXED_WINDOW_FUNCTIONS
        . "\nlocal load, calculate, persist = load_fixed, calculate_fixed, persist_fixed\nlocal now\n"
        . self::SCALAR_ADMISSION_SCRIPT;

    private const string SLIDING_WINDOW_SCRIPT = self::SLIDING_WINDOW_FUNCTIONS
        . "\nlocal load, calculate, persist = load_sliding, calculate_sliding, persist_sliding\nlocal now\n"
        . self::SCALAR_ADMISSION_SCRIPT;

    private const string LEAKY_BUCKET_SCRIPT = self::LEAKY_BUCKET_FUNCTIONS
        . "\nlocal load, calculate, persist = load_leaky, calculate_leaky, persist_leaky\n"
        . "local time = redis.call('TIME')\nlocal now = tonumber(time[1]) * 1000000 + tonumber(time[2])\n"
        . self::SCALAR_ADMISSION_SCRIPT;

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

    if failures > MAX_INTEGER or availableAt > MAX_INTEGER then
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

    private const string COOLDOWN_SCRIPT = <<<'LUA'
local MAX_INTEGER = 9007199254740991
local mode = ARGV[1]
local duration = tonumber(ARGV[2])
local time = redis.call('TIME')
local now = tonumber(time[1]) * 1000000 + tonumber(time[2])
local raw = redis.call('GET', KEYS[1])
local expiresAt = 0

if raw then
    if raw ~= '0' and not string.match(raw, '^[1-9]%d*$') then
        return redis.error_reply('ERR corrupt rate limiter cooldown state')
    end

    expiresAt = tonumber(raw)

    if expiresAt > MAX_INTEGER then
        return redis.error_reply('ERR corrupt rate limiter cooldown state')
    end

    local ttl = redis.call('PTTL', KEYS[1])
    if ttl == -1 then
        return redis.error_reply('ERR corrupt rate limiter cooldown state has no expiry')
    end
    if ttl <= 0 or expiresAt <= now then
        expiresAt = 0
    end
end

if mode == 'inspect' then
    local retry = math.max(expiresAt - now, 0)
    return {retry == 0 and 1 or 0, 0, 0, retry, 0}
end

if now > MAX_INTEGER - duration then
    return redis.error_reply('ERR rate limiter cooldown timestamp overflow')
end

expiresAt = math.max(expiresAt, now + duration)
local retry = expiresAt - now
local ttl = math.floor(retry / 1000)

if retry % 1000 ~= 0 then
    ttl = ttl + 1
end

redis.call('SET', KEYS[1], expiresAt)
redis.call('PEXPIRE', KEYS[1], ttl)

return {0, 0, 0, retry, 0}
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
        return $this->executeAdmission($key, $policy, 'consume');
    }

    /**
     * Consume a group atomically, or inspect then consume on Redis Cluster.
     *
     * Cluster preflight denials charge nothing. A denial during consumption
     * can leave earlier charges, including when a group repeats a key.
     *
     * @param list<array{key: string, policy: AdmissionPolicy}> $policies
     * @return list<LimitResult>
     */
    public function consumeMany(array $policies): array
    {
        if ($policies === []) {
            return [];
        }

        return $this->redis->connection($this->connection)->withConnection(function (RedisConnection $connection) use ($policies): array {
            if ($connection->isCluster()) {
                return $this->consumeManyOnCluster($connection, $policies);
            }

            $arguments = [];
            $capacities = [];

            foreach ($policies as $entry) {
                [$kind, $parameters, $capacity] = $this->admissionParameters($entry['policy']);
                $arguments[] = $kind;
                $capacities[] = $capacity;

                foreach ($parameters as $parameter) {
                    $arguments[] = $parameter;
                }

                if (count($parameters) === 3) {
                    $arguments[] = '0';
                }
            }

            $tuples = $connection->evalWithShaCache(self::CONSUME_MANY_SCRIPT, array_column($policies, 'key'), $arguments);

            if (! is_array($tuples) || ! array_is_list($tuples) || $tuples === [] || count($tuples) > count($policies)) {
                throw new UnexpectedValueException('Redis returned a malformed rate limiter group result.');
            }

            $results = [];

            foreach ($tuples as $index => $tuple) {
                $results[] = $this->limitResult($tuple, $capacities[$index]);
            }

            return $results;
        }, transform: false);
    }

    /**
     * Check distributed keys before consuming them individually.
     *
     * @param list<array{key: string, policy: AdmissionPolicy}> $policies
     * @return list<LimitResult>
     */
    protected function consumeManyOnCluster(RedisConnection $connection, array $policies): array
    {
        foreach (['inspect', 'consume'] as $operation) {
            $results = [];

            foreach ($policies as $entry) {
                $result = $this->executeAdmission($entry['key'], $entry['policy'], $operation, $connection);
                $results[] = $result;

                if ($result->denied()) {
                    return $results;
                }
            }
        }

        return $results;
    }

    /**
     * Atomically extend a cooldown block.
     */
    public function block(string $key, int $durationMicroseconds): CooldownResult
    {
        return $this->executeCooldown($key, $durationMicroseconds, 'block');
    }

    /**
     * Inspect a policy without mutating its state.
     *
     * @return ($policy is Backoff ? BackoffResult : ($policy is Cooldown ? CooldownResult : LimitResult))
     */
    public function inspect(
        string $key,
        AdmissionPolicy|Backoff|Cooldown $policy,
    ): LimitResult|BackoffResult|CooldownResult {
        return match (true) {
            $policy instanceof AdmissionPolicy => $this->executeAdmission($key, $policy, 'inspect'),
            $policy instanceof Backoff => $this->executeBackoff($key, $policy, 'inspect'),
            default => $this->executeCooldown($key, 0, 'inspect'),
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
     * Execute an admission operation, reusing a held connection for Cluster groups.
     */
    protected function executeAdmission(
        string $key,
        AdmissionPolicy $policy,
        string $mode,
        ?RedisConnection $connection = null,
    ): LimitResult {
        [$kind, $parameters, $capacity] = $this->admissionParameters($policy);
        $script = match ($kind) {
            'fixed' => self::FIXED_WINDOW_SCRIPT,
            'sliding' => self::SLIDING_WINDOW_SCRIPT,
            'leaky' => self::LEAKY_BUCKET_SCRIPT,
        };
        $arguments = [$mode, ...$parameters];

        $result = $connection === null
            ? $this->execute($script, $key, $arguments)
            : $connection->evalWithShaCache($script, [$key], $arguments);

        return $this->limitResult($result, $capacity);
    }

    /**
     * Return the algorithm, script parameters, and capacity for an admission policy.
     *
     * @return array{'fixed'|'leaky'|'sliding', list<string>, int}
     */
    protected function admissionParameters(AdmissionPolicy $policy): array
    {
        return match (true) {
            $policy instanceof Limit => ['fixed', [
                (string) $policy->cost, (string) $policy->maxAttempts, (string) ($policy->decaySeconds * 1000),
            ], $policy->maxAttempts],
            $policy instanceof SlidingWindow => ['sliding', [
                (string) $policy->cost, (string) $policy->maxAttempts, (string) $policy->windowSeconds,
            ], $policy->maxAttempts],
            $policy instanceof LeakyBucket => ['leaky', [
                (string) $policy->cost, (string) $policy->rate, (string) $policy->periodMicroseconds, (string) $policy->burst,
            ], $policy->burst],
            default => throw new InvalidRateLimitException(sprintf('Admission policy [%s] is not supported.', $policy::class)),
        };
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
     * Execute a cooldown operation.
     */
    protected function executeCooldown(string $key, int $durationMicroseconds, string $mode): CooldownResult
    {
        $values = $this->integerTuple($this->execute(self::COOLDOWN_SCRIPT, $key, [
            $mode,
            (string) $durationMicroseconds,
        ]));

        if ($values[1] !== 0 || $values[2] !== 0 || $values[4] !== 0) {
            throw new UnexpectedValueException('Redis returned an invalid rate limiter cooldown result.');
        }

        return new CooldownResult($values[0] === 1, $values[3]);
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
