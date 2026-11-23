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
     */
    // @phpstan-ignore hypervel.legacyQueueProperty (public property; removing it would leave assignments to it without declared storage)
    public bool $failOnTimeout = true;

    /**
     * Configure the job.
     */
    protected function configureJob(): void
    {
        if (! isset($this->tries) && ($tries = config()->nullableInteger('scout.jobs.tries', null)) !== null) {
            $this->tries = $tries;
        }

        if (! isset($this->backoff)
            && ! method_exists($this, 'backoff')
            // @phpstan-ignore hypervel.untypedConfigRead (Job backoff accepts a number of seconds or a retry schedule.)
            && ($backoff = config('scout.jobs.backoff')) !== null) {
            $this->backoff = $backoff;
        }

        if (! isset($this->maxExceptions)
            && ($maxExceptions = config()->nullableInteger('scout.jobs.max_exceptions', null)) !== null) {
            $this->maxExceptions = $maxExceptions;
        }
    }
}
