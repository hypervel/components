<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Contracts\Cache\Repository as CacheContract;
use SessionHandlerInterface;

class CacheBasedSessionHandler implements SessionHandlerInterface
{
    /**
     * Create a new cache driven handler instance.
     *
     * @param CacheContract $cache the cache repository instance
     * @param int $minutes the number of minutes to store the data in the cache
     */
    public function __construct(
        protected CacheContract $cache,
        protected int $minutes
    ) {
    }

    public function open(string $savePath, string $sessionName): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $sessionId): string
    {
        return $this->cache->get($sessionId, '');
    }

    public function write(string $sessionId, string $data): bool
    {
        return $this->cache->put($sessionId, $data, $this->minutes * 60);
    }

    public function destroy(string $sessionId): bool
    {
        return $this->cache->forget($sessionId);
    }

    public function gc(int $lifetime): int
    {
        return 0;
    }

    /**
     * Get the underlying cache repository.
     */
    public function getCache(): CacheContract
    {
        return $this->cache;
    }
}
