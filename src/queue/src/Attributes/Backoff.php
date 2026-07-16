<?php

declare(strict_types=1);

namespace Hypervel\Queue\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class Backoff
{
    /**
     * The backoff values.
     *
     * @var array<int>|int
     */
    public array|int $backoff;

    /**
     * Create a new attribute instance.
     *
     * @param array<int>|int ...$backoff Seconds to wait before retrying the job.
     */
    public function __construct(array|int ...$backoff)
    {
        $backoff = array_values($backoff);

        $this->backoff = count($backoff) === 1 ? $backoff[0] : $backoff;
    }
}
