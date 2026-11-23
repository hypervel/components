<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Closure;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Support\Attributes\BootOnly;
use Hypervel\Support\Attributes\BootOrTestsOnly;
use Hypervel\Support\Attributes\TestsOnly;
use InvalidArgumentException;
use ReflectionException;
use RuntimeException;
use UnitEnum;

abstract class Manager
{
    use RebindsCallbacksToSelf;

    /**
     * The configuration repository instance.
     */
    protected Repository $config;

    /**
     * The registered custom driver creators.
     *
     * @var array<array-key, Closure>
     */
    protected array $customCreators = [];

    /**
     * The array of created "drivers".
     *
     * @var array<array-key, mixed>
     */
    protected array $drivers = [];

    /**
     * Create a new manager instance.
     */
    public function __construct(
        protected Container $container
    ) {
        $this->config = $container->make('config');
    }

    /**
     * Get the default driver name.
     */
    abstract public function getDefaultDriver(): ?string;

    /**
     * Get a driver instance.
     *
     * @throws InvalidArgumentException
     */
    public function driver(UnitEnum|string|null $driver = null): mixed
    {
        if ($driver instanceof UnitEnum) {
            $driver = (string) enum_value($driver);
        }

        $driver = $driver === null || $driver === ''
            ? $this->getDefaultDriver()
            : $driver;

        if ($driver === null) {
            throw new InvalidArgumentException(sprintf(
                'Unable to resolve NULL driver for [%s].',
                static::class
            ));
        }

        // If the given driver has not been created before, we will create the instances
        // here and cache it so we can return it next time very quickly. If there is
        // already a driver created by this name, we'll just return that instance.
        if (! isset($this->drivers[$driver])) {
            $this->drivers[$driver] = $this->createDriver($driver);
        }

        return $this->drivers[$driver];
    }

    /**
     * Create a new driver instance.
     *
     * @throws InvalidArgumentException
     */
    protected function createDriver(string $driver): mixed
    {
        // First, we will determine if a custom driver creator exists for the given driver and
        // if it does not we will check for a creator method for the driver. Custom creator
        // callbacks allow developers to build their own "drivers" easily using Closures.
        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($driver);
        }

        $method = $this->getCreatorMethod($driver);

        if ($method !== null) {
            return $this->{$method}();
        }

        throw new InvalidArgumentException("Driver [{$driver}] not supported.");
    }

    /**
     * Get the name of the manager's creator method for the given driver.
     */
    protected function getCreatorMethod(string $driver): ?string
    {
        $method = 'create' . Str::studly($driver) . 'Driver';

        // Hypervel: a name that studly-cases to nothing, such as '' or '-', would resolve to createDriver() itself.
        return $method !== 'createDriver' && method_exists($this, $method) ? $method : null;
    }

    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(string $driver): mixed
    {
        return $this->customCreators[$driver]($this->container);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * Anonymous closures run in this manager's class scope; non-static closures
     * also receive the manager as $this.
     *
     * The callback persists in the singleton's customCreators array for the
     * worker lifetime and applies to every subsequent driver resolution.
     *
     * @return $this
     */
    #[BootOnly(whenShared: true)]
    public function extend(string $driver, Closure $callback): static
    {
        try {
            $callback = $this->bindCallbackToSelf($callback)
                ?? throw new RuntimeException('Unable to bind custom driver callback');
        } catch (ReflectionException $e) {
            throw new RuntimeException('Unable to bind custom driver callback', previous: $e);
        }

        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Get all of the created "drivers".
     *
     * @return array<array-key, mixed>
     */
    public function getDrivers(): array
    {
        return $this->drivers;
    }

    /**
     * Get the container instance used by the manager.
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Set the container instance used by the manager.
     *
     * Swaps the singleton's container and configuration references; per-request
     * use races across coroutines and breaks every concurrent driver resolution
     * through this manager.
     *
     * @return $this
     */
    #[TestsOnly(whenShared: true)]
    public function setContainer(Container $container): static
    {
        $this->container = $container;
        $this->config = $container->make('config');

        return $this;
    }

    /**
     * Forget all of the resolved driver instances.
     *
     * Clears the singleton's driver cache; concurrent coroutines may
     * already hold references that next resolution will not share.
     *
     * @return $this
     */
    #[BootOrTestsOnly(whenShared: true)]
    public function forgetDrivers(): static
    {
        $this->drivers = [];

        return $this;
    }

    /**
     * Dynamically call the default driver instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->driver()->{$method}(...$parameters);
    }
}
