<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Attributes;

abstract class ServerAttribute
{
    /**
     * Create a server attribute.
     */
    public function __construct(public string $value)
    {
    }
}
