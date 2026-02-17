<?php

declare(strict_types=1);

namespace Hypervel\Pipeline;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pipeline\Hub as HubContract;

class Hub implements HubContract
{
    /**
     * The container implementation.
     */
    protected ?Container $container;

    /**
     * All of the available pipelines.
     */
    protected array $pipelines = [];

    /**
     * Create a new Hub instance.
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container;
    }

    /**
     * Define the default named pipeline.
     */
    public function defaults(Closure $callback): void
    {
        $this->pipeline('default', $callback);
    }

    /**
     * Define a new named pipeline.
     */
    public function pipeline(string $name, Closure $callback): void
    {
        $this->pipelines[$name] = $callback;
    }

    /**
     * Send an object through one of the available pipelines.
     */
    public function pipe(mixed $object, ?string $pipeline = null): mixed
    {
        $pipeline = $pipeline ?: 'default';

        return call_user_func(
            $this->pipelines[$pipeline],
            new Pipeline($this->container),
            $object
        );
    }

    /**
     * Get the container instance used by the hub.
     */
    public function getContainer(): ?Container
    {
        return $this->container;
    }

    /**
     * Set the container instance used by the hub.
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }
}
