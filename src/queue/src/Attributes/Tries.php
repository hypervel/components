<?php

declare(strict_types=1);

namespace Hypervel\Queue\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Tries
{
    /**
     * Create a new attribute instance.
     */
    public function __construct(
        public int $tries,
    ) {
    }
}
