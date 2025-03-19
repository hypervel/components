<?php

declare(strict_types=1);

namespace Hypervel\Container;

use Hyperf\Di\Definition\DefinitionSource as HyperfDefinitionSource;

class DefinitionSource extends HyperfDefinitionSource
{
    /**
     * Remove specific defined source.
     */
    public function removeDefinition(string $name): void
    {
        unset($this->source[$name]);
    }
}
