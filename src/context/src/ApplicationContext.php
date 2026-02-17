<?php

declare(strict_types=1);

namespace Hypervel\Context;

use Hypervel\Contracts\Container\Container as ContainerContract;
use Psr\Container\ContainerInterface;
use TypeError;

/**
 * TODO: Remove "extends \Hyperf\Context\ApplicationContext" once all Hyperf dependencies are removed.
 * We temporarily extend the parent to share the static $container storage with Hyperf
 * vendor code that still uses Hyperf\Context\ApplicationContext.
 * Once porting is complete, remove the extends and uncomment $container below.
 */
class ApplicationContext extends \Hyperf\Context\ApplicationContext
{
    /**
     * TODO: Uncomment when removing "extends \Hyperf\Context\ApplicationContext".
     */
    // protected static ?ContainerInterface $container = null;

    /**
     * Get the application container instance.
     *
     * @throws TypeError
     */
    public static function getContainer(): ContainerContract
    {
        /* @phpstan-ignore-next-line */
        return self::$container;
    }

    /**
     * Determine if the container has been set.
     */
    public static function hasContainer(): bool
    {
        return isset(self::$container);
    }

    /**
     * Set the application container instance.
     */
    public static function setContainer(ContainerInterface $container): ContainerInterface
    {
        self::$container = $container;
        return $container;
    }
}
