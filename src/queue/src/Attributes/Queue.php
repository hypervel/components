<?php

declare(strict_types=1);

namespace Hypervel\Queue\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Queue
{
    /**
     * Create a new attribute instance.
     */
    public function __construct(
        public string $queue,
    ) {
    }
}
