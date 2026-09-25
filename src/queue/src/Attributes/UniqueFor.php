<?php

declare(strict_types=1);

namespace Hypervel\Queue\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
readonly class UniqueFor
{
    /**
     * Create a new attribute instance.
     *
     * @param int $uniqueFor seconds to consider the queueable unique for
     */
    public function __construct(
        public int $uniqueFor,
    ) {
    }
}
