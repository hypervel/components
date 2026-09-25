<?php

declare(strict_types=1);

namespace Hypervel\Queue\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class Timeout
{
    /**
     * Create a new attribute instance.
     *
     * @param int $timeout seconds before the job is considered timed out
     */
    public function __construct(
        public int $timeout,
    ) {
    }
}
