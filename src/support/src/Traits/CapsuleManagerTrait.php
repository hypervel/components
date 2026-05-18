<?php

declare(strict_types=1);

namespace Hypervel\Support\Traits;

use Hypervel\Contracts\Container\Container;
use Hypervel\Support\Fluent;

trait CapsuleManagerTrait
{
    /**
     * The current globally used instance.
     */
    protected static ?object $instance = null;

    /**
     * The container instance.
     */
    protected Container $container;

    /**
     * Setup the IoC container instance.
     */
    protected function setupContainer(Container $container): void
    {
        $this->container = $container;

        if (! $this->container->bound('config')) {
            $this->container->instance('config', new Fluent);
        }
    }

    /**
     * Make this capsule instance available globally.
     *
     * Boot or tests only. Swaps the global Capsule instance used by static
     * calls; request-time use races across coroutines.
     */
    public function setAsGlobal(): void
    {
        static::$instance = $this;
    }

    /**
     * Get the IoC container instance.
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Set the IoC container instance.
     *
     * Boot or tests only. Swaps the Capsule container used by this standalone
     * manager; request-time use races across coroutines.
     */
    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$instance = null;
    }
}
