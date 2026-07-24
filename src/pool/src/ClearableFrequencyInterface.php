<?php

declare(strict_types=1);

namespace Hypervel\Pool;

interface ClearableFrequencyInterface
{
    /**
     * Clear the frequency's owned resources.
     *
     * Pool closure reports failures from this method and continues draining
     * the pool, but the strategy remains responsible for its own cleanup.
     */
    public function clear(): void;
}
