<?php

declare(strict_types=1);

namespace Hypervel\Queue\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class RetryFor
{
    /**
     * The duration of the retry window in seconds.
     */
    public int $retryFor;

    /**
     * Create a new attribute instance.
     *
     * @param int $seconds seconds to keep retrying the queueable for
     * @param int $minutes minutes to keep retrying the queueable for
     * @param int $hours hours to keep retrying the queueable for
     */
    public function __construct(int $seconds = 0, int $minutes = 0, int $hours = 0)
    {
        $this->retryFor = $seconds + ($minutes * 60) + ($hours * 3600);
    }
}
