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
     * @param int|int[]|string $backoff the seconds to wait before retrying a failed job, or a schedule as a list or comma-separated string
     */
    public function __construct(
        string $name = 'default',
        ?string $environment = null,
        array|int|string $backoff = 0,
        float|string $memory = 128,
        int $timeout = 60,
        float $sleep = 3,
        int $maxTries = 1,
        bool $force = false,
        float $rest = 0
    ) {
        $this->environment = $environment;

        parent::__construct(
            name: $name,
            backoff: $backoff,
            memory: $memory,
            timeout: $timeout,
            sleep: $sleep,
            maxTries: $maxTries,
            force: $force,
            rest: $rest,
        );
    }
}
