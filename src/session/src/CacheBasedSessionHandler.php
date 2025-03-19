<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Cache\Contracts\Factory as CacheContract;
use Hypervel\Cache\Contracts\Repository as RepositoryContract;
use SessionHandlerInterface;

class CacheBasedSessionHandler implements SessionHandlerInterface
{
    /**
     * Create a new cache driven handler instance.
     *
     * @param CacheContract $cache the cache factory instance
     * @param null|string $store the store name of connection
     * @param int $minutes the number of minutes to store the data in the cache
     */
    public function __construct(
        protected CacheContract $cache,
        protected ?string $store,
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
        return $this->getStore()->get($sessionId, '');
    }

    public function write(string $sessionId, string $data): bool
    {
        return $this->getStore()->put($sessionId, $data, $this->minutes * 60);
    }

    public function destroy(string $sessionId): bool
    {
        return $this->getStore()->forget($sessionId);
    }

    public function gc(int $lifetime): int
    {
        return 0;
    }

    public function getCache(): CacheContract
    {
        return $this->cache;
    }

    public function getStore(): RepositoryContract
    {
        return $this->cache->store($this->store);
    }
}
