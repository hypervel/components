<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Carbon\CarbonInterval;
use Hypervel\Cache\Contracts\Factory as Cache;
use Hypervel\Cache\Contracts\LockProvider;
use Hypervel\Console\Contracts\CommandMutex;
use Hypervel\Support\Traits\InteractsWithTime;

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
        $store = $this->cache->store($this->store);

        $expiresAt = method_exists($command, 'isolationLockExpiresAt')
            ? $command->isolationLockExpiresAt()
            : CarbonInterval::hour();

        $cacheStore = $store->getStore();

        if ($cacheStore instanceof LockProvider) {
            return $cacheStore->lock(
                $this->commandMutexName($command),
                $this->secondsUntil($expiresAt)
            )->get();
        }

        return $store->add($this->commandMutexName($command), true, $expiresAt);
    }

    /**
     * Determine if a command mutex exists for the given command.
     */
    public function exists(Command $command): bool
    {
        $store = $this->cache->store($this->store);

        $cacheStore = $store->getStore();

        if ($cacheStore instanceof LockProvider) {
            $lock = $cacheStore->lock($this->commandMutexName($command));

            return tap(! $lock->get(), function ($exists) use ($lock) {
                if ($exists) {
                    $lock->release();
                }
            });
        }

        return $this->cache->store($this->store)->has($this->commandMutexName($command));
    }

    /**
     * Release the mutex for the given command.
     */
    public function forget(Command $command): bool
    {
        $store = $this->cache->store($this->store);

        $cacheStore = $store->getStore();

        if ($cacheStore instanceof LockProvider) {
            return $cacheStore->lock($this->commandMutexName($command))->forceRelease();
        }

        return $this->cache->store($this->store)->forget($this->commandMutexName($command));
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
     * Specify the cache store that should be used.
     */
    public function useStore(?string $store): static
    {
        $this->store = $store;

        return $this;
    }

}
