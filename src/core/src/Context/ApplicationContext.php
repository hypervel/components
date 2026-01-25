<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Hyperf\Context\ApplicationContext as HyperfApplicationContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use TypeError;

class ApplicationContext extends HyperfApplicationContext
{
    /**
     * @throws TypeError
     */
    public static function getContainer(): ContainerContract
    {
        /* @phpstan-ignore-next-line */
        return self::$container;
    }
}
