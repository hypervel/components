<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Tools\Annotations;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class IsDestructive extends ToolAnnotation
{
    /**
     * Indicate whether the tool can modify or destroy data.
     */
    public function __construct(public bool $value = true)
    {
    }

    /**
     * Get the annotation key.
     */
    public function key(): string
    {
        return 'destructiveHint';
    }
}
