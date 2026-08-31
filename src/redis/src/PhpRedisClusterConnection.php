<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Pool\Exceptions\ConnectionException;
use InvalidArgumentException;
use Redis;
use RedisCluster;
use RedisException;
use Throwable;

/**
 * Redis Cluster connection using phpredis RedisCluster client.
 */
class PhpRedisClusterConnection extends PhpRedisConnection
{
    /**
     * Error reply prefixes that standalone phpredis exposes as false.
     */
    private const array NON_THROWING_ERROR_PREFIXES = [
        'NOSCRIPT',
        'NOQUORUM',
        'NOGOODSLAVE',
        'WRONGTYPE',
        'BUSYGROUP',
        'NOGROUP',
    ];

    /**
     * The default node to use from the cluster.
     */
    protected string|array|null $defaultNode = null;

    /**
     * Reconnect to Redis Cluster.
     *
     * @throws ConnectionException
     */
    public function reconnect(): bool
    {
        $this->defaultNode = null;

        $redis = $this->createRedisCluster();

        $this->setOptions($redis);

        // RedisCluster handles auth in its constructor, no separate auth call needed.
        // RedisCluster doesn't support select(), no database selection.

        $this->connection = $redis;
        $this->markReconnected();

        if ($this->config['events'] && $this->container->bound('events')) {
            $this->eventDispatcher = $this->container->make('events');
        }

        return true;
    }

    /**
     * Determine if the connection is to a Redis Cluster.
     */
    public function isCluster(): bool
    {
        return true;
    }

    /**
     * Determine if the underlying Redis Cluster client is in pipeline/multi mode.
     */
    protected function isQueueingMode(): bool
    {
        return $this->connection->getMode() !== Redis::ATOMIC;
    }

    /**
     * Get the master nodes in the cluster.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public function masters(): array
    {
        return $this->connection->_masters();
    }

    /**
     * Scan all keys based on options.
     *
     * Overrides the base scan to include a node parameter for RedisCluster,
     * which requires specifying which node to scan.
     *
     * @param mixed $cursor
     * @param array $arguments
     */
    public function scan(&$cursor, ...$arguments): mixed
    {
        if (! $this->shouldTransform) {
            return $this->__call('scan', array_merge([&$cursor], $arguments));
        }

        $options = $this->getScanOptions($arguments);

        $result = $this->connection->scan(
            $cursor,
            $options['node'] ?? $this->defaultNode(),
            $options['match'] ?? '*',
            $options['count'] ?? 10
        );

        if ($result === false) {
            $result = [];
        }

        return $cursor === 0 && empty($result) ? false : [$cursor, $result];
    }

    /**
     * Get server information from the default Cluster node.
     */
    protected function callInfo(string ...$sections): array|false
    {
        /** @var RedisCluster $connection */
        $connection = $this->connection;
        /** @var array|false $result */
        $result = $connection->info($this->defaultNode(), ...$sections);

        return $result;
    }

    /**
     * Ping the default Cluster node.
     */
    protected function callPing(?string $message = null): bool|string
    {
        if ($message === null) {
            return $this->connection->ping($this->defaultNode());
        }

        return $this->connection->ping($this->defaultNode(), $message);
    }

    /**
     * Evaluate a script and preserve standalone phpredis error semantics.
     */
    protected function callEval(string $script, int $numberOfKeys, mixed ...$arguments): mixed
    {
        $this->connection->clearLastError();

        $result = parent::callEval($script, $numberOfKeys, ...$arguments);

        $this->throwIfStandaloneWouldThrow($result);

        return $result;
    }

    /**
     * Evaluate a script through the topology-aware SHA cache.
     */
    protected function callEvalsha(string $script, int $numkeys, mixed ...$arguments): mixed
    {
        return $this->evalWithShaCache(
            $script,
            array_slice($arguments, 0, $numkeys),
            array_slice($arguments, $numkeys),
        );
    }

    /**
     * Prepare a raw command for the node owning its first argument.
     *
     * @return array{string, array<int, mixed>}
     */
    protected function prepareExecuteRaw(mixed ...$arguments): array
    {
        /** @var array<int, mixed> $parameters */
        $parameters = $arguments[0];
        /** @var string $command */
        $command = array_shift($parameters);
        $route = $parameters[0] ?? $this->defaultNode();

        // Raw multi-key callers remain responsible for placing every key in one slot.
        return ['rawCommand', [$route, $command, ...$parameters]];
    }

    /**
     * Execute a raw command against the node owning its first argument.
     */
    protected function callExecuteRaw(array $parameters): mixed
    {
        return $this->normalizeNullReplies(parent::callExecuteRaw($parameters));
    }

    /**
     * Flush the selected Redis database on all master nodes.
     */
    protected function callFlushdb(mixed ...$arguments): mixed
    {
        $async = strtoupper((string) ($arguments[0] ?? null)) === 'ASYNC';
        $successful = true;

        foreach ($this->masters() as $master) {
            if ($async) {
                $result = $this->connection->rawCommand($master, 'flushdb', 'async');
            } else {
                $result = $this->connection->flushdb($master); // @phpstan-ignore argument.type (connection is always RedisCluster here)
            }

            $successful = $result !== false && $successful;
        }

        return $successful;
    }

    /**
     * Create an exception that preserves standalone phpredis semantics.
     */
    protected function scriptException(string $error): Throwable
    {
        return $this->standaloneWouldThrow($error)
            ? new RedisException($error)
            : parent::scriptException($error);
    }

    /**
     * Normalize RedisCluster's null representation of RESP nil replies.
     */
    protected function normalizeNullReplies(mixed $result): mixed
    {
        if (! is_array($result)) {
            return $result === null ? false : $result;
        }

        return array_map(
            fn (mixed $value): mixed => $this->normalizeNullReplies($value),
            $result,
        );
    }

    /**
     * Restore the exception boundary exposed by standalone phpredis.
     *
     * Direct eval mirrors phpredis's false return, while the SHA helper wraps script errors.
     */
    private function throwIfStandaloneWouldThrow(mixed $result): void
    {
        if ($result !== false || ($error = $this->connection->getLastError()) === null) {
            return;
        }

        // Mirrors phpredis library.c::redis_error_throw(). RedisCluster's variant
        // parser returns false for every TYPE_ERR reply instead of applying this rule.
        if ($this->standaloneWouldThrow($error)) {
            throw new RedisException($error);
        }
    }

    /**
     * Determine whether standalone phpredis throws a Redis error reply.
     */
    protected function standaloneWouldThrow(string $error): bool
    {
        $ordinaryError = str_starts_with($error, 'ERR')
            && ! str_starts_with($error, 'ERR AUTH');

        return ! $ordinaryError
            && ! array_any(
                self::NON_THROWING_ERROR_PREFIXES,
                fn (string $prefix): bool => str_starts_with($error, $prefix),
            );
    }

    /**
     * Create a Redis Cluster connection.
     *
     * @throws ConnectionException
     */
    protected function createRedisCluster(): RedisCluster
    {
        try {
            $parameters = [
                null,
                $this->config['cluster']['seeds'],
                $this->config['timeout'],
                $this->config['read_timeout'],
                false,
                $this->formatClusterPassword(),
            ];

            if ($this->config['scheme'] === 'tls') {
                // RedisCluster needs the context argument to carry TLS to endpoints discovered after bootstrapping.
                $parameters[] = $this->normalizeClusterContext(
                    $this->config['context']
                );
            }

            $redis = new RedisCluster(...$parameters);
        } catch (Throwable $exception) {
            if ($cancellation = RedisCancellation::cancellationFrom(
                $exception,
                'Connecting to Redis Cluster was canceled.',
            )) {
                throw $cancellation;
            }

            throw new ConnectionException(
                'Connection reconnect failed: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        return $redis;
    }

    /**
     * Normalize the SSL context for a Redis Cluster connection.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function normalizeClusterContext(array $context): array
    {
        if (isset($context['ssl']) && is_array($context['ssl'])) {
            return $context['ssl'];
        }

        if (isset($context['stream']) && is_array($context['stream'])) {
            return $context['stream'];
        }

        return $context;
    }

    /**
     * Format the password for the RedisCluster constructor.
     */
    protected function formatClusterPassword(): mixed
    {
        $password = $this->config['password'];
        $username = $this->config['username'];

        return $username !== null && $username !== '' && is_string($password)
            ? [$username, $password]
            : $password;
    }

    /**
     * Return default node to use for cluster.
     *
     * @throws InvalidArgumentException
     */
    private function defaultNode(): string|array
    {
        if ($this->defaultNode === null) {
            $this->defaultNode = $this->connection->_masters()[0]
                ?? throw new InvalidArgumentException('Unable to determine default node. No master nodes found in the cluster.');
        }

        return $this->defaultNode;
    }
}
