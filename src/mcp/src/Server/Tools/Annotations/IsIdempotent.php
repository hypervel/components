<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Tools\Annotations;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class IsIdempotent extends ToolAnnotation
{
    /**
     * Indicate whether repeating the tool call has the same effect.
     */
    public function __construct(public bool $value = true)
    {
    }

    /**
     * Get the annotation key.
     */
    public function key(): string
    {
        return 'idempotentHint';
    }
}
