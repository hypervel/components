<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Queue;

interface Interruptible
{
    /**
     * Handle a signal received by the queue worker.
     */
    public function interrupted(int $signal): void;
}
