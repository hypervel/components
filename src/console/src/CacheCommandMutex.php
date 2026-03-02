<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Carbon\CarbonInterval;
use Hypervel\Contracts\Cache\Factory as Cache;
use Hypervel\Contracts\Cache\LockProvider;
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
        $store = $this->cache->store($this->store);

        $expiresAt = method_exists($command, 'isolationLockExpiresAt')
            ? $command->isolationLockExpiresAt()
            : CarbonInterval::hour();

        if ($this->shouldUseLocks($store->getStore())) {
            /* @phpstan-ignore-next-line */
            return $store->getStore()->lock(
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

        if ($this->shouldUseLocks($store->getStore())) {
            /* @phpstan-ignore-next-line */
            $lock = $store->getStore()->lock($this->commandMutexName($command));

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

        if ($this->shouldUseLocks($store->getStore())) {
            /* @phpstan-ignore-next-line */
            return $store->getStore()->lock($this->commandMutexName($command))->forceRelease();
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
     * Determine if the given store should use locks for command mutexes.
     */
    protected function shouldUseLocks(mixed $store): bool
    {
        return $store instanceof LockProvider;
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
