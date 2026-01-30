<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Psr\Container\ContainerInterface;
use TypeError;

class ApplicationContext
{
    protected static ?ContainerInterface $container = null;

    /**
     * @throws TypeError
     */
    public static function getContainer(): ContainerContract
    {
        /* @phpstan-ignore-next-line */
        return self::$container;
    }

    public static function hasContainer(): bool
    {
        return isset(self::$container);
    }

    public static function setContainer(ContainerInterface $container): ContainerInterface
    {
        self::$container = $container;
        return $container;
    }
}
