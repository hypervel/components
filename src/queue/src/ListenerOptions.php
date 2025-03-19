<?php

declare(strict_types=1);

namespace Hypervel\Queue;

class ListenerOptions extends WorkerOptions
{
    /**
     * The environment the worker should run in.
     */
    public ?string $environment = null;

    /**
     * Create a new listener options instance.
     *
     * @param int|int[] $backoff
     */
    public function __construct(
        string $name = 'default',
        ?string $environment = null,
        array|int $backoff = 0,
        int $memory = 128,
        int $timeout = 60,
        int $sleep = 3,
        int $maxTries = 1,
        bool $force = false,
        int $rest = 0
    ) {
        $this->environment = $environment;

        parent::__construct($name, $backoff, $memory, $timeout, $sleep, $maxTries, $force, false, 0, 0, $rest);
    }
}
