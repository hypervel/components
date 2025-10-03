<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Hyperf\Context\ApplicationContext as HyperfApplicationContext;
use Psr\Container\ContainerInterface;
use TypeError;

class ApplicationContext extends HyperfApplicationContext
{
    /**
     * @return \Hypervel\Container\Contracts\Container
     * @throws TypeError
     */
    public static function getContainer(): ContainerInterface
    {
        /* @phpstan-ignore-next-line */
        return self::$container;
    }
}
