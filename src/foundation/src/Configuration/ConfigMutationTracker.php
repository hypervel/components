<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Configuration;

use Hypervel\Config\Repository;

/**
 * @internal
 */
class ConfigMutationTracker
{
    /**
     * The ordered configuration mutations made during application boot.
     *
     * @var list<array<array-key, mixed>>
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
     * Replay the recorded mutations and stop tracking changes in this worker.
     *
     * Boot-only. Calling this outside the before-worker-start boundary
     * permanently stops recording and can omit later boot mutations.
     */
    public function replay(Repository $config): void
    {
        $this->recording = false;

        foreach ($this->mutations as $mutation) {
            $config->set($mutation);
        }
    }
}
