<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Closure;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Foundation\Application;
use InvalidArgumentException;
use RuntimeException;

abstract class MultipleInstanceManager
{
    /**
     * The configuration repository instance.
     */
    protected Repository $config;

    /**
     * The array of resolved instances.
     */
    protected array $instances = [];

    /**
     * The registered custom instance creators.
     */
    protected array $customCreators = [];

    /**
     * The key name of the "driver" equivalent configuration option.
     */
    protected string $driverKey = 'driver';

    /**
     * Create a new manager instance.
     */
    public function __construct(
        protected Application $app
    ) {
        $this->config = $app->make('config');
    }

    /**
     * Get the default instance name.
     */
    abstract public function getDefaultInstance(): string;

    /**
     * Set the default instance name.
     *
     * Implementations that mutate process-global config are not safe for per-request
     * use under Swoole. Consider using coroutine context for request-scoped overrides.
     */
    abstract public function setDefaultInstance(string $name): void;

    /**
     * Get the instance specific configuration.
     */
    abstract public function getInstanceConfig(string $name): array;

    /**
     * Get an instance by name.
     */
    public function instance(?string $name = null): mixed
    {
        $name = $name ?: $this->getDefaultInstance();

        return $this->instances[$name] = $this->get($name);
    }

    /**
     * Attempt to get an instance from the local cache.
     */
    protected function get(string $name): mixed
    {
        return $this->instances[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given instance.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function resolve(string $name): mixed
    {
        $config = $this->getInstanceConfig($name);

        if (! array_key_exists($this->driverKey, $config)) {
            throw new RuntimeException("Instance [{$name}] does not specify a {$this->driverKey}.");
        }

        $driverName = $config[$this->driverKey];

        if (isset($this->customCreators[$driverName])) {
            return $this->callCustomCreator($config);
        }

        $createMethod = 'create' . ucfirst($driverName) . ucfirst($this->driverKey);

        if (method_exists($this, $createMethod)) {
            return $this->{$createMethod}($config);
        }

        $createMethod = 'create' . Str::studly($driverName) . ucfirst($this->driverKey);

        if (method_exists($this, $createMethod)) {
            return $this->{$createMethod}($config);
        }

        throw new InvalidArgumentException("Instance {$this->driverKey} [{$config[$this->driverKey]}] is not supported.");
    }

    /**
     * Call a custom instance creator.
     */
    protected function callCustomCreator(array $config): mixed
    {
        return $this->customCreators[$config[$this->driverKey]]($this->app, $config);
    }

    /**
     * Unset the given instances.
     *
     * @return $this
     */
    public function forgetInstance(array|string|null $name = null): static
    {
        $name ??= $this->getDefaultInstance();

        foreach ((array) $name as $instanceName) {
            if (isset($this->instances[$instanceName])) {
                unset($this->instances[$instanceName]);
            }
        }

        return $this;
    }

    /**
     * Disconnect the given instance and remove from local cache.
     */
    public function purge(?string $name = null): void
    {
        $name ??= $this->getDefaultInstance();

        unset($this->instances[$name]);
    }

    /**
     * Register a custom instance creator Closure.
     *
     * @param-closure-this $this $callback
     *
     * @return $this
     */
    public function extend(string $name, Closure $callback): static
    {
        $this->customCreators[$name] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Set the application instance used by the manager.
     *
     * @return $this
     */
    public function setApplication(Application $app): static
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Dynamically call the default instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->instance()->{$method}(...$parameters);
    }
}
