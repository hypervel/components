<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Tools\Annotations;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class IsReadOnly extends ToolAnnotation
{
    /**
     * Create a read-only tool annotation.
     */
    public function __construct(public bool $value = true)
    {
    }

    /**
     * Get the annotation key.
     */
    public function key(): string
    {
        return 'readOnlyHint';
    }
}
