<?php

declare(strict_types=1);

namespace Hypervel\Queue\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class DebounceFor
{
    /**
     * Create a new attribute instance.
     *
     * @param int $debounceFor seconds to debounce the job for
     * @param null|int $maxWait the maximum number of seconds the job can be deferred before it is forced to run
     */
    public function __construct(
        public int $debounceFor,
        public ?int $maxWait = null
    ) {
    }
}
