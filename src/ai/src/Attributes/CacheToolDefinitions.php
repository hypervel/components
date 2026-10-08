<?php

declare(strict_types=1);

namespace Hypervel\Ai\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class CacheToolDefinitions
{
    /**
     * Create a tool definition cache attribute.
     */
    public function __construct(public ?string $ttl = null)
    {
    }
}
