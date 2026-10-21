<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Data\Data;

class SimpleDataWithPropertyHooks extends Data
{
    public string $virtual {
        get => 'virtual';
    }

    public string $backed = 'default' {
        get => strtoupper($this->backed);
    }

    public string $backedConstructorProperty {
        get => strtoupper($this->constructorProperty);
    }

    /**
     * Create a fixture with virtual and backed property hooks.
     */
    public function __construct(
        protected string $constructorProperty = 'default'
    ) {
    }
}
