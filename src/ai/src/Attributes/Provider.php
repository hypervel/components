<?php

declare(strict_types=1);

namespace Hypervel\Ai\Attributes;

use Attribute;
use Hypervel\Ai\Enums\Lab;

#[Attribute(Attribute::TARGET_CLASS)]
class Provider
{
    /**
     * Create a provider selection attribute.
     */
    public function __construct(public Lab|array|string $value)
    {
    }
}
