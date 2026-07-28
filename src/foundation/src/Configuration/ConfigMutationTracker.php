<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Configuration;

use Closure;
use Hypervel\Config\Repository;

/**
 * Tracks configuration mutations made during application boot for worker replay.
 *
 * This is framework boot infrastructure rather than a userland extension point.
 */
class ConfigMutationTracker
{
    /**
     * The ordered configuration mutations made during application boot.
     *
     * @var list<array<array-key, mixed>|Closure(Repository): void>
     */
    protected array $mutations = [];

    /**
     * Whether configuration mutations should be recorded.
     */
    protected bool $recording = true;

    /**
     * Observe configuration mutations made during application boot.
     *
     * Boot-only. Calling this outside application bootstrap replaces
     * Foundation's mutation observer and can track the wrong lifecycle.
     */
    public function observe(Repository $config): void
    {
        $config->setMutationObserver(function (array $values): void {
            if ($this->recording) {
                $this->mutations[] = $values;
            }
        });
    }

    /**
     * Apply and record a configuration operation for worker replay.
     *
     * Boot-only. The operation is retained for worker-start replay and is
     * re-evaluated against the freshly rebuilt configuration repository.
     *
     * @param Closure(Repository): void $mutation
     */
    public function applyAndRecord(Repository $config, Closure $mutation): void
    {
        $wasRecording = $this->recording;
        $this->recording = false;

        try {
            $mutation($config);
        } finally {
            $this->recording = $wasRecording;
        }

        if ($wasRecording) {
            $this->mutations[] = $mutation;
        }
    }

    /**
     * Replay the recorded mutations and stop tracking changes in this worker.
     *
     * Boot-only. Calling this outside the before-worker-start boundary
     * permanently stops recording and can omit later boot mutations.
     */
    public function replay(Repository $config): void
    {
        $this->recording = false;

        foreach ($this->mutations as $mutation) {
            if ($mutation instanceof Closure) {
                $mutation($config);

                continue;
            }

            $config->set($mutation);
        }
    }
}
