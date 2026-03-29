<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console\Concerns;

trait ParsesQueue
{
    /**
     * Parse the queue argument into connection and queue name.
     *
     * @return array{string, string}
     */
    protected function parseQueue(string $queue): array
    {
        [$connection, $queue] = array_pad(explode(':', $queue, 2), -2, null);

        return [
            $connection ?? $this->hypervel['config']['queue.default'],
            $queue ?: 'default',
        ];
    }
}
