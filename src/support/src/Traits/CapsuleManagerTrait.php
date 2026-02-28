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
            $this->container->instance('config', new Fluent());
        }
    }

    /**
     * Make this capsule instance available globally.
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
     */
    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }
}
