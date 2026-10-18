<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Tools\Annotations;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class IsOpenWorld extends ToolAnnotation
{
    /**
     * Indicate whether the tool interacts with an open world.
     */
    public function __construct(public bool $value = true)
    {
    }

    /**
     * Get the annotation key.
     */
    public function key(): string
    {
        return 'openWorldHint';
    }
}
