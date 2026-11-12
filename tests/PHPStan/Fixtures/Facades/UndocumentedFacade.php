<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Facades;

use Hypervel\Support\Facades\Facade;

/**
 * A facade without @method annotations, whose calls the analysis types from the booted root.
 */
class UndocumentedFacade extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return UndocumentedFacadeRoot::class;
    }
}
