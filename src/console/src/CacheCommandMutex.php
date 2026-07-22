<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Carbon\CarbonInterval;
use Hypervel\Contracts\Cache\Factory as Cache;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Support\InteractsWithTime;

class CacheCommandMutex implements CommandMutex
{
    use InteractsWithTime;

    /**
     * The cache store that should be used.
     */
    public ?string $store = null;

    public function __construct(
        public Cache $cache
    ) {
    }

    /**
     * Attempt to obtain a command mutex for the given command.
     */
    public function create(Command $command): bool
    {
        $repository = $this->cache->store($this->store);
        $store = $repository->getStore();

        $expiresAt = method_exists($command, 'isolationLockExpiresAt')
            ? $command->isolationLockExpiresAt()
            : CarbonInterval::hour();

        if ($this->shouldUseLocks($store)) {
            return $store->lock(
                $this->commandMutexName($command),
                $this->secondsUntil($expiresAt)
            )->get();
        }

        return $repository->add($this->commandMutexName($command), true, $expiresAt);
    }

    /**
     * Determine if a command mutex exists for the given command.
     */
    public function exists(Command $command): bool
    {
        $repository = $this->cache->store($this->store);
        $store = $repository->getStore();

        if ($this->shouldUseLocks($store)) {
            return ! $store
                ->lock($this->commandMutexName($command))
                ->get(fn () => true);
        }

        return $repository->has($this->commandMutexName($command));
    }

    /**
     * Release the mutex for the given command.
     */
    public function forget(Command $command): bool
    {
        $repository = $this->cache->store($this->store);
        $store = $repository->getStore();

        if ($this->shouldUseLocks($store)) {
            $store->lock($this->commandMutexName($command))->forceRelease();

            return true;
        }

        return $repository->forget($this->commandMutexName($command));
    }

    /**
     * Get the isolatable command mutex name.
     */
    protected function commandMutexName(Command $command): string
    {
        $baseName = 'framework' . DIRECTORY_SEPARATOR . 'command-' . $command->getName();

        return method_exists($command, 'isolatableId')
            ? $baseName . '-' . $command->isolatableId()
            : $baseName;
    }

    /**
     * Determine if the given store should use locks for command mutexes.
     *
     * @phpstan-assert-if-true LockProvider $store
     */
    protected function shouldUseLocks(Store $store): bool
    {
        return $store instanceof LockProvider;
    }

    /**
     * Specify the cache store that should be used.
     *
     * Boot-only. Mutates the shared command mutex instance for the worker
     * lifetime; per-request use races across coroutines.
     */
    public function useStore(?string $store): static
    {
        $this->store = $store;

        return $this;
    }
}
