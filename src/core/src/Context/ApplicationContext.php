<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Hyperf\Context\ApplicationContext as HyperfApplicationContext;
use Hypervel\Container\Contracts\Container as ContainerContract;
use TypeError;

class ApplicationContext extends HyperfApplicationContext
{
    /**
     * @throws TypeError
     */
    public static function getContainer(): ContainerContract
    {
        $container = parent::getContainer();

        if (! $container instanceof ContainerContract) {
            throw new TypeError('The application container must implement ' . ContainerContract::class . '.');
        }

        return $container;
    }
}
