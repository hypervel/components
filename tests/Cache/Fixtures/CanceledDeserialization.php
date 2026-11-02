<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache\Fixtures;

use RuntimeException;
use Swoole\Coroutine\CanceledException;

class CanceledDeserialization
{
    /**
     * Configure whether restoration cleanup fails during cancellation.
     */
    public function __construct(private bool $wrapped)
    {
    }

    /**
     * Simulate canceled restoration of a cached application's object.
     */
    public function __wakeup(): void
    {
        try {
            throw new CanceledException('Restoration canceled.');
        } finally {
            if ($this->wrapped) {
                throw new RuntimeException('Restoration cleanup failed.');
            }
        }
    }
}
