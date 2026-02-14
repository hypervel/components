<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Stub;

use Redis;

/**
 * Fake Redis client for testing SCAN/HSCAN operations with proper reference parameter handling.
 *
 * Extends Redis to satisfy type hints (Redis|RedisCluster) while providing
 * controlled test behavior. Does NOT connect to any real Redis server.
 *
 * Mockery's andReturnUsing doesn't properly propagate modifications to reference
 * parameters back to the caller. This stub properly implements the &$iterator
 * reference parameter behavior that phpredis's scan()/hScan() uses.
 *
 * Usage for SCAN:
 * ```php
 * $client = new FakeRedisClient(
 *     scanResults: [
 *         ['keys' => ['key1', 'key2'], 'iterator' => 100],  // First scan: continue
 *         ['keys' => ['key3'], 'iterator' => 0],            // Second scan: done
 *     ]
 * );
 * ```
 *
 * Usage for HSCAN:
 * ```php
 * $client = new FakeRedisClient(
 *     hScanResults: [
 *         'hash:key' => [
 *             ['fields' => ['f1' => 'v1', 'f2' => 'v2'], 'iterator' => 100],
 *             ['fields' => ['f3' => 'v3'], 'iterator' => 0],
 *         ],
 *     ]
 * );
 * ```
 *
 * @internal For testing only - does not connect to Redis
 */
class FakeRedisClient extends Redis
{
    /**
     * Configured scan results: array of ['keys' => [...], 'iterator' => int].
     *
     * @var array<int, array{keys: array<string>, iterator: int}>
     */
    private array $scanResults;

    /**
     * Current scan call index.
     */
    private int $scanCallIndex = 0;

    /**
     * Recorded scan calls for assertions.
     *
     * @var array<int, array{pattern: ?string, count: int}>
     */
    private array $scanCalls = [];

    /**
     * Configured hScan results per hash key.
     *
     * @var array<string, array<int, array{fields: array<string, string>, iterator: int}>>
     */
    private array $hScanResults;

    /**
     * Current hScan call index per hash key.
     *
     * @var array<string, int>
     */
    private array $hScanCallIndex = [];

    /**
     * Recorded hScan calls for assertions.
     *
     * @var array<int, array{key: string, pattern: string, count: int}>
     */
    private array $hScanCalls = [];

    /**
     * Pipeline mode flag.
     */
    private bool $inPipeline = false;

    /**
     * Queued pipeline commands.
     *
     * @var array<int, array{method: string, args: array, result: mixed}>
     */
    private array $pipelineQueue = [];

    /**
     * Configured exec() results for pipeline operations.
     *
     * @var array<int, array<mixed>>
     */
    private array $execResults = [];

    /**
     * Current exec call index.
     */
    private int $execCallIndex = 0;

    /**
     * Configured zRange results per key.
     *
     * @var array<string, array<string>>
     */
    private array $zRangeResults = [];

    /**
     * Configured hLen results per key.
     *
     * @var array<string, int>
     */
    private array $hLenResults = [];

    /**
     * Configured OPT_PREFIX value for getOption().
     */
    private string $optPrefix = '';

    /**
     * Configured zScan results per key.
     *
     * @var array<string, array<int, array{members: array<string, float>, iterator: int}>>
     */
    private array $zScanResults = [];

    /**
     * Current zScan call index per key.
     *
     * @var array<string, int>
     */
    private array $zScanCallIndex = [];

    /**
     * Recorded zScan calls for assertions.
     *
     * @var array<int, array{key: string, pattern: ?string, count: int}>
     */
    private array $zScanCalls = [];

    /**
     * Recorded zRem calls for assertions.
     *
     * @var array<int, array{key: string, members: array<string>}>
     */
    private array $zRemCalls = [];

    /**
     * Configured zCard results per key.
     *
     * @var array<string, int>
     */
    private array $zCardResults = [];

    /**
     * Configured zRemRangeByScore results per key (for sequential execution).
     *
     * @var array<string, int>
     */
    private array $zRemRangeByScoreResults = [];

    /**
     * Create a new fake Redis client.
     *
     * @param array<int, array{keys: array<string>, iterator: int}> $scanResults Configured scan results
     * @param array<int, array<mixed>> $execResults Configured exec() results for pipelines
     * @param array<string, array<int, array{fields: array<string, string>, iterator: int}>> $hScanResults Configured hScan results
     * @param array<string, array<string>> $zRangeResults Configured zRange results
     * @param array<string, int> $hLenResults Configured hLen results
     * @param string $optPrefix Configured OPT_PREFIX value
     * @param array<string, array<int, array{members: array<string, float>, iterator: int}>> $zScanResults Configured zScan results
     * @param array<string, int> $zCardResults Configured zCard results per key
     * @param array<string, int> $zRemRangeByScoreResults Configured zRemRangeByScore results per key
     */
    public function __construct(
        array $scanResults = [],
        array $execResults = [],
        array $hScanResults = [],
        array $zRangeResults = [],
        array $hLenResults = [],
        string $optPrefix = '',
        array $zScanResults = [],
        array $zCardResults = [],
        array $zRemRangeByScoreResults = [],
    ) {
        // Note: We intentionally do NOT call parent::__construct() to avoid
        // any connection attempts. This fake client never connects to Redis.
        $this->scanResults = $scanResults;
        $this->execResults = $execResults;
        $this->hScanResults = $hScanResults;
        $this->zRangeResults = $zRangeResults;
        $this->hLenResults = $hLenResults;
        $this->optPrefix = $optPrefix;
        $this->zScanResults = $zScanResults;
        $this->zCardResults = $zCardResults;
        $this->zRemRangeByScoreResults = $zRemRangeByScoreResults;
    }

    /**
     * Simulate Redis SCAN with proper reference parameter handling.
     *
     * @param null|int|string $iterator Cursor (modified by reference)
     * @param null|string $pattern Optional pattern to match
     * @param int $count Optional count hint
     * @param null|string $type Optional type filter
     * @return array<string>|false
     */
    public function scan(int|string|null &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null): array|false
    {
        // Record the call for assertions
        $this->scanCalls[] = ['pattern' => $pattern, 'count' => $count];

        if (! isset($this->scanResults[$this->scanCallIndex])) {
            $iterator = 0;
            return false;
        }

        $result = $this->scanResults[$this->scanCallIndex];
        $iterator = $result['iterator'];
        ++$this->scanCallIndex;

        return $result['keys'];
    }

    /**
     * Get recorded scan calls for test assertions.
     *
     * @return array<int, array{pattern: ?string, count: int}>
     */
    public function getScanCalls(): array
    {
        return $this->scanCalls;
    }

    /**
     * Get the number of scan() calls made.
     */
    public function getScanCallCount(): int
    {
        return count($this->scanCalls);
    }

    /**
     * Simulate Redis HSCAN with proper reference parameter handling.
     *
     * @param string $key Hash key
     * @param null|int|string $iterator Cursor (modified by reference)
     * @param null|string $pattern Optional pattern to match
     * @param int $count Optional count hint
     * @return array<string, string>|bool|Redis
     */
    public function hscan(string $key, int|string|null &$iterator, ?string $pattern = null, int $count = 0): Redis|array|bool
    {
        // Record the call for assertions
        $this->hScanCalls[] = ['key' => $key, 'pattern' => $pattern, 'count' => $count];

        // Initialize call index for this key if not set
        if (! isset($this->hScanCallIndex[$key])) {
            $this->hScanCallIndex[$key] = 0;
        }

        if (! isset($this->hScanResults[$key][$this->hScanCallIndex[$key]])) {
            $iterator = 0;
            return false;
        }

        $result = $this->hScanResults[$key][$this->hScanCallIndex[$key]];
        $iterator = $result['iterator'];
        ++$this->hScanCallIndex[$key];

        return $result['fields'];
    }

    /**
     * Get recorded hScan calls for test assertions.
     *
     * @return array<int, array{key: string, pattern: string, count: int}>
     */
    public function getHScanCalls(): array
    {
        return $this->hScanCalls;
    }

    /**
     * Get the number of hScan() calls made.
     */
    public function getHScanCallCount(): int
    {
        return count($this->hScanCalls);
    }

    /**
     * Simulate getOption() for compression and prefix checks.
     */
    public function getOption(int $option): mixed
    {
        return match ($option) {
            Redis::OPT_COMPRESSION => Redis::COMPRESSION_NONE,
            Redis::OPT_PREFIX => $this->optPrefix,
            default => null,
        };
    }

    /**
     * Simulate zRange to get sorted set members.
     *
     * @return array<string>|false|Redis
     */
    public function zRange(string $key, string|int $start, string|int $end, array|bool|null $options = null): Redis|array|false
    {
        return $this->zRangeResults[$key] ?? [];
    }

    /**
     * Simulate hLen to get hash length.
     */
    public function hLen(string $key): Redis|int|false
    {
        return $this->hLenResults[$key] ?? 0;
    }

    /**
     * Queue exists in pipeline or execute directly.
     *
     * @return $this|bool|int
     */
    public function exists(mixed $key, mixed ...$other_keys): Redis|int|bool
    {
        $keys = is_array($key) ? $key : array_merge([$key], $other_keys);

        if ($this->inPipeline) {
            $this->pipelineQueue[] = ['method' => 'exists', 'args' => $keys];
            return $this;
        }
        return 0;
    }

    /**
     * Queue hDel in pipeline or execute directly.
     *
     * @return $this|false|int
     */
    public function hDel(string $key, string ...$fields): Redis|int|false
    {
        if ($this->inPipeline) {
            $this->pipelineQueue[] = ['method' => 'hDel', 'args' => [$key, ...$fields]];
            return $this;
        }
        return count($fields);
    }

    /**
     * Enter pipeline mode.
     *
     * @return $this
     */
    public function pipeline(): Redis
    {
        $this->inPipeline = true;
        $this->pipelineQueue = [];
        return $this;
    }

    /**
     * Execute pipeline and return results.
     *
     * @return array<mixed>|false
     */
    public function exec(): array|false
    {
        $this->inPipeline = false;

        if (isset($this->execResults[$this->execCallIndex])) {
            $result = $this->execResults[$this->execCallIndex];
            ++$this->execCallIndex;
            return $result;
        }

        // Return empty array if no more configured results
        return [];
    }

    /**
     * Queue zRemRangeByScore in pipeline or execute directly.
     *
     * @return $this|false|int
     */
    public function zRemRangeByScore(string $key, string $min, string $max): Redis|int|false
    {
        if ($this->inPipeline) {
            $this->pipelineQueue[] = ['method' => 'zRemRangeByScore', 'args' => [$key, $min, $max]];
            return $this;
        }
        return $this->zRemRangeByScoreResults[$key] ?? 0;
    }

    /**
     * Queue zCard in pipeline or execute directly.
     *
     * @return $this|false|int
     */
    public function zCard(string $key): Redis|int|false
    {
        if ($this->inPipeline) {
            $this->pipelineQueue[] = ['method' => 'zCard', 'args' => [$key]];
            return $this;
        }
        return $this->zCardResults[$key] ?? 0;
    }

    /**
     * Queue del in pipeline or execute directly.
     *
     * @return $this|false|int
     */
    public function del(array|string $key, string ...$other_keys): Redis|int|false
    {
        $keys = is_array($key) ? $key : array_merge([$key], $other_keys);

        if ($this->inPipeline) {
            $this->pipelineQueue[] = ['method' => 'del', 'args' => $keys];
            return $this;
        }
        return count($keys);
    }

    /**
     * Simulate Redis ZSCAN with proper reference parameter handling.
     *
     * @param string $key Sorted set key
     * @param null|int|string $iterator Cursor (modified by reference)
     * @param null|string $pattern Optional pattern to match
     * @param int $count Optional count hint
     * @return array<string, float>|false|Redis
     */
    public function zscan(string $key, int|string|null &$iterator, ?string $pattern = null, int $count = 0): Redis|array|false
    {
        // Record the call for assertions
        $this->zScanCalls[] = ['key' => $key, 'pattern' => $pattern, 'count' => $count];

        // Initialize call index for this key if not set
        if (! isset($this->zScanCallIndex[$key])) {
            $this->zScanCallIndex[$key] = 0;
        }

        if (! isset($this->zScanResults[$key][$this->zScanCallIndex[$key]])) {
            $iterator = 0;
            return false;
        }

        $result = $this->zScanResults[$key][$this->zScanCallIndex[$key]];
        $iterator = $result['iterator'];
        ++$this->zScanCallIndex[$key];

        return $result['members'];
    }

    /**
     * Get recorded zScan calls for test assertions.
     *
     * @return array<int, array{key: string, pattern: ?string, count: int}>
     */
    public function getZScanCalls(): array
    {
        return $this->zScanCalls;
    }

    /**
     * Simulate Redis ZREM.
     *
     * @return false|int Number of members removed
     */
    public function zRem(mixed $key, mixed $member, mixed ...$other_members): Redis|int|false
    {
        $allMembers = array_merge([$member], $other_members);
        $this->zRemCalls[] = ['key' => $key, 'members' => $allMembers];
        return count($allMembers);
    }

    /**
     * Get recorded zRem calls for test assertions.
     *
     * @return array<int, array{key: string, members: array<string>}>
     */
    public function getZRemCalls(): array
    {
        return $this->zRemCalls;
    }

    /**
     * Reset the client state for reuse in tests.
     * Note: This is a test helper, not the Redis::reset() connection reset.
     */
    public function resetFakeState(): void
    {
        $this->scanCallIndex = 0;
        $this->scanCalls = [];
        $this->hScanCallIndex = [];
        $this->hScanCalls = [];
        $this->zScanCallIndex = [];
        $this->zScanCalls = [];
        $this->zRemCalls = [];
        $this->execCallIndex = 0;
        $this->inPipeline = false;
        $this->pipelineQueue = [];
    }
}
