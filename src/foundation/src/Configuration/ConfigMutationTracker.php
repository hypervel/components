<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Configuration;

use Closure;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Config\Repository as RepositoryContract;
use Hypervel\Support\Attributes\BootOnly;

/**
 * Record directly written configuration keys during static analysis bootstrap.
 *
 * @internal
 */
class ConfigMutationTracker
{
    /**
     * The configuration keys written directly during bootstrap.
     *
     * @var array<array-key, true>
     */
    protected array $keys = [];

    /**
     * Whether direct writes are being recorded.
     */
    protected bool $recording = true;

    /**
     * The repository to detach from when analysis bootstrap finishes.
     */
    protected ?Repository $repository = null;

    /**
     * Observe configuration writes after the configuration files have loaded.
     */
    #[BootOnly]
    public function observe(Repository $config): void
    {
        $this->repository = $config;
        $config->setMutationObserver(function (array $values): void {
            if ($this->recording) {
                foreach ($values as $key => $value) {
                    $this->keys[$key] = true;
                }
            }
        });
    }

    /**
     * Detach from the observed repository when analysis bootstrap finishes.
     */
    #[BootOnly]
    public function stopObserving(): void
    {
        $this->repository?->setMutationObserver(null);
        $this->repository = null;
    }

    /**
     * Apply a provider operation whose configuration sources are analyzed separately.
     *
     * @param Closure(RepositoryContract): void $callback
     */
    #[BootOnly]
    public function withoutRecording(RepositoryContract $config, Closure $callback): void
    {
        $wasRecording = $this->recording;
        $this->recording = false;

        try {
            $callback($config);
        } finally {
            $this->recording = $wasRecording;
        }
    }

    /**
     * Get the keys written directly during bootstrap.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->keys));
    }
}
