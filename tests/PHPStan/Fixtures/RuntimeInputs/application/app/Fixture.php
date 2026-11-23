<?php

declare(strict_types=1);

namespace RuntimeInputs;

use Hypervel\Support\Facades\Facade;

class Fixture extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'fixture.root';
    }
}
