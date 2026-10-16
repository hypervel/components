<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Contracts;

interface Annotation
{
    /**
     * Get the annotation key.
     */
    public function key(): string;
}
