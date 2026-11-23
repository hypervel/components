<?php

declare(strict_types=1);

namespace Hypervel\Support\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Unsupported
{
    /**
     * Create a new attribute instance.
     *
     * @param null|string $message guidance for callers
     */
    public function __construct(
        public ?string $message = null,
    ) {
    }
}
