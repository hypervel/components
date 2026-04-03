<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Fixtures;

use Redis;
use RedisCluster;

/**
 * Fake RedisCluster client for testing cluster-specific operations.
 *
 * Extends RedisCluster to satisfy type hints while providing controlled
 * test behavior. Does NOT connect to any real Redis Cluster.
 *
 * @internal For testing only - does not connect to Redis
 */
class FakeRedisClusterClient extends RedisCluster
{
    /**
     * Configured master nodes.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private array $masters;

    /**
     * Configured scan results per node.
     *
     * @var array<string, array<int, array{keys: array<string>, iterator: int}>>
     */
    private array $scanResults;

    /**
     * Current scan call index per node.
     *
     * @var array<string, int>
     */
    private array $scanCallIndex = [];

    /**
     * Recorded scan calls for assertions.
     *
     * @var array<int, array{node: array|string, pattern: ?string, count: int}>
     */
    private array $scanCalls = [];

    /**
     * Recorded flushdb calls for assertions.
     *
     * @var array<int, array{node: array|string, async: bool}>
     */
    private array $flushdbCalls = [];

    /**
     * Recorded rawCommand calls for assertions.
     *
     * @var array<int, array{args: array<mixed>}>
     */
    private array $rawCommandCalls = [];

    /**
     * Configured OPT_PREFIX value for getOption().
     */
    private string $optPrefix;

    /**
     * Create a new fake RedisCluster client.
     *
     * Does NOT call parent::__construct() to avoid real connection attempts.
     *
     * @param array<int, array{0: string, 1: int}> $masters Configured master nodes
     * @param array<string, array<int, array{keys: array<string>, iterator: int}>> $scanResults Scan results per node key
     * @param string $optPrefix Configured OPT_PREFIX value
     */
    public function __construct(
        array $masters = [['127.0.0.1', 6379]],
        array $scanResults = [],
        string $optPrefix = '',
    ) {
        // Intentionally do NOT call parent::__construct() to avoid
        // any connection attempts. This fake client never connects.
        $this->masters = $masters;
        $this->scanResults = $scanResults;
        $this->optPrefix = $optPrefix;
    }

    /**
     * Return configured master nodes.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public function _masters(): array
    {
        return $this->masters;
    }

    /**
     * Simulate RedisCluster SCAN with node parameter.
     *
     * @param null|int|string $iterator Cursor (modified by reference)
     * @param array|string $node The node to scan
     * @param null|string $pattern Optional pattern to match
     * @param int $count Optional count hint
     * @return array<string>|false
     */
    public function scan(int|string|null &$iterator, string|array|null $node = null, ?string $pattern = null, int $count = 0): array|false
    {
        $nodeKey = is_array($node) ? implode(':', $node) : (string) $node;

        $this->scanCalls[] = ['node' => $node, 'pattern' => $pattern, 'count' => $count];

        if (! isset($this->scanCallIndex[$nodeKey])) {
            $this->scanCallIndex[$nodeKey] = 0;
        }

        if (! isset($this->scanResults[$nodeKey][$this->scanCallIndex[$nodeKey]])) {
            $iterator = 0;
            return false;
        }

        $result = $this->scanResults[$nodeKey][$this->scanCallIndex[$nodeKey]];
        $iterator = $result['iterator'];
        ++$this->scanCallIndex[$nodeKey];

        return $result['keys'];
    }

    /**
     * Record flushdb calls for assertions.
     */
    public function flushdb(string|array|null $node = null, ?bool $sync = null): RedisCluster|bool
    {
        $this->flushdbCalls[] = ['node' => $node, 'async' => false];

        return true;
    }

    /**
     * Record rawCommand calls for assertions.
     */
    public function rawCommand(string|array $node, string $command, mixed ...$args): mixed
    {
        $this->rawCommandCalls[] = ['args' => [$node, $command, ...$args]];

        return true;
    }

    /**
     * Simulate getOption() for prefix and compression checks.
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
     * Get recorded scan calls for test assertions.
     *
     * @return array<int, array{node: array|string, pattern: ?string, count: int}>
     */
    public function getScanCalls(): array
    {
        return $this->scanCalls;
    }

    /**
     * Get recorded flushdb calls for test assertions.
     *
     * @return array<int, array{node: array|string, async: bool}>
     */
    public function getFlushdbCalls(): array
    {
        return $this->flushdbCalls;
    }

    /**
     * Get recorded rawCommand calls for test assertions.
     *
     * @return array<int, array{args: array<mixed>}>
     */
    public function getRawCommandCalls(): array
    {
        return $this->rawCommandCalls;
    }
}
