<?php

declare(strict_types=1);

namespace Hypervel\Queue\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Delay
{
    /**
     * Create a new attribute instance.
     *
     * @param int $delay seconds to delay the job for
     */
    public function __construct(
        public int $delay,
    ) {
    }
}
