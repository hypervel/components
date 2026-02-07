<?php

declare(strict_types=1);

namespace Hypervel\Redis\Lua;

use Hyperf\Contract\StdoutLoggerInterface;
use Hypervel\Redis\Exceptions\RedisNotFoundException;
use Hypervel\Redis\Redis;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

abstract class Script implements ScriptInterface
{
    /**
     * PHPRedis client or proxy client.
     * @var mixed|\Redis
     */
    protected mixed $redis;

    protected ?string $sha = null;

    protected ?LoggerInterface $logger = null;

    /**
     * Create a new script instance.
     */
    public function __construct(ContainerInterface $container)
    {
        if ($container->has(Redis::class)) {
            $this->redis = $container->get(Redis::class);
        }

        if ($container->has(StdoutLoggerInterface::class)) {
            $this->logger = $container->get(StdoutLoggerInterface::class);
        }
    }

    /**
     * Evaluate the script.
     *
     * @param array<int, mixed> $arguments
     */
    public function eval(array $arguments = [], bool $sha = true): mixed
    {
        if ($this->redis === null) {
            throw new RedisNotFoundException('Redis client is not found.');
        }

        if ($sha) {
            $result = $this->redis->evalSha($this->getSha(), $arguments, $this->getKeyNumber($arguments));
            if ($result !== false) {
                return $this->format($result);
            }

            $this->sha = null;
            $this->logger && $this->logger->warning(sprintf('NOSCRIPT No matching script[%s]. Use EVAL instead.', static::class));
        }

        $result = $this->redis->eval($this->getScript(), $arguments, $this->getKeyNumber($arguments));

        return $this->format($result);
    }

    /**
     * Get the script key count.
     *
     * @param array<int, mixed> $arguments
     */
    protected function getKeyNumber(array $arguments): int
    {
        return count($arguments);
    }

    /**
     * Get or load the script SHA.
     */
    protected function getSha(): string
    {
        if (! empty($this->sha)) {
            return $this->sha;
        }

        return $this->sha = $this->redis->script('load', $this->getScript());
    }
}
