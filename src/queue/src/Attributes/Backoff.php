<?php

declare(strict_types=1);

namespace Hypervel\Queue\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Backoff
{
    /**
     * Create a new attribute instance.
     *
     * @param array<int>|int $backoff
     */
    public function __construct(
        public array|int $backoff,
    ) {
    }
}
