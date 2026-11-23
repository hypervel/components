<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Concerns;

use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Throwable;

/** @internal */
trait HasContainer
{
    protected ?ContainerContract $container = null;

    /**
     * Set the container used for resolution.
     */
    public function setContainer(ContainerContract $container): void
    {
        $this->container = $container;
    }

    /**
     * Return the current container.
     */
    public function getContainer(): ContainerContract
    {
        return $this->container ?? Container::getInstance();
    }

    /**
     * Resolve the given type from the container.
     */
    public function resolve(string $abstract): mixed
    {
        try {
            $concrete = $this->getContainer()->make($abstract);
        } catch (Throwable) {
            return null;
        }

        return $concrete;
    }
}
