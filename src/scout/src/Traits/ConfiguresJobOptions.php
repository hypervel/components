<?php

declare(strict_types=1);

namespace Hypervel\Scout\Traits;

trait ConfiguresJobOptions
{
    /**
     * The number of times the job may be attempted.
     *
     * @var null|int
     */
    public $tries;

    /**
     * The number of seconds to wait before retrying the job when encountering an uncaught exception.
     *
     * @var null|int|list<int>
     */
    public $backoff;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var null|int
     */
    public $maxExceptions;

    /**
     * Indicates if the job should be marked as failed on timeout.
     *
     * @var bool
     */
    public $failOnTimeout = true;

    /**
     * Configure the job.
     */
    protected function configureJob(): void
    {
        if (! isset($this->tries) && ($tries = config('scout.jobs.tries')) !== null) {
            $this->tries = $tries;
        }

        if (! isset($this->backoff)
            && ! method_exists($this, 'backoff')
            && ($backoff = config('scout.jobs.backoff')) !== null) {
            $this->backoff = $backoff;
        }

        if (! isset($this->maxExceptions)
            && ($maxExceptions = config('scout.jobs.max_exceptions')) !== null) {
            $this->maxExceptions = $maxExceptions;
        }
    }
}
