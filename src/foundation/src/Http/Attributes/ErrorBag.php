<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class ErrorBag
{
    /**
     * Create a new attribute instance.
     */
    public function __construct(
        public string $name
    ) {
    }
}
