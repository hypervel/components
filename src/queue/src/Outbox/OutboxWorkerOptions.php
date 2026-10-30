<?php

declare(strict_types=1);

namespace Hypervel\Queue\Outbox;

use Hypervel\Queue\MemoryLimit;
use InvalidArgumentException;

readonly class OutboxWorkerOptions
{
    /**
     * Create the limits for one relay invocation.
     */
    public function __construct(
        public int $batch = 100,
        public float $sleep = 1,
        public int $concurrency = 1,
        public int $maxJobs = 0,
        public int $maxTime = 0,
        public float|string $memory = 128,
        public bool $stopWhenEmpty = false,
        public bool $once = false,
    ) {
        if ($batch < 1 || $batch > 1000) {
            throw new InvalidArgumentException('The outbox batch size must be between 1 and 1000.');
        }

        if ($sleep <= 0 || $concurrency < 1) {
            throw new InvalidArgumentException('Outbox sleep and concurrency must be greater than zero.');
        }

        if ($maxJobs < 0 || $maxTime < 0 || MemoryLimit::toMegabytes($memory) <= 0) {
            throw new InvalidArgumentException('Outbox job and time limits must be non-negative, and memory must be greater than zero.');
        }
    }
}
