<?php

declare(strict_types=1);

namespace Hypervel\Queue\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class MaxExceptions
{
    /**
     * Create a new attribute instance.
     */
    public function __construct(
        public int $maxExceptions,
    ) {
    }
}
