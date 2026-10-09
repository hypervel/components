<?php

declare(strict_types=1);

namespace Hypervel\Ai;

use Hypervel\Support\Facades\Facade;

/**
 * @see AiManager
 *
 * @mixin AiManager
 */
class Ai extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return AiManager::class;
    }
}
