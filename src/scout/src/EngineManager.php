<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Closure;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Scout\Engines\CollectionEngine;
use Hypervel\Scout\Engines\MeilisearchEngine;
use Hypervel\Scout\Engines\NullEngine;
use InvalidArgumentException;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Meilisearch;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Manages search engine instances and driver creation.
 *
 * Engine instances are cached statically because they hold no request-specific
 * state and are safe to share across coroutines.
 */
class EngineManager
{
    /**
     * The resolved engine instances (process-global cache).
     *
     * @var array<string, Engine>
     */
    private static array $engines = [];

    /**
     * The registered custom driver creators.
     *
     * @var array<string, Closure>
     */
    protected array $customCreators = [];

    /**
     * Create a new engine manager instance.
     */
    public function __construct(
        protected ContainerInterface $container
    ) {
    }

    /**
     * Get an engine instance by name.
     */
    public function engine(?string $name = null): Engine
    {
        $name ??= $this->getDefaultDriver();

        return self::$engines[$name] ??= $this->resolve($name);
    }

    /**
     * Resolve the given engine.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): Engine
    {
        if (isset($this->customCreators[$name])) {
            return $this->callCustomCreator($name);
        }

        $driverMethod = 'create' . ucfirst($name) . 'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}();
        }

        throw new InvalidArgumentException("Driver [{$name}] is not supported.");
    }

    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(string $name): Engine
    {
        return $this->customCreators[$name]($this->container);
    }

    /**
     * Create a Meilisearch engine instance.
     */
    public function createMeilisearchDriver(): MeilisearchEngine
    {
        $this->ensureMeilisearchClientIsInstalled();

        return new MeilisearchEngine(
            $this->container->get(MeilisearchClient::class),
            $this->getConfig('soft_delete', false)
        );
    }

    /**
     * Ensure the Meilisearch client is installed.
     *
     * @throws RuntimeException
     */
    protected function ensureMeilisearchClientIsInstalled(): void
    {
        if (class_exists(Meilisearch::class) && version_compare(Meilisearch::VERSION, '1.0.0', '>=')) {
            return;
        }

        throw new RuntimeException(
            'Please install the Meilisearch client: meilisearch/meilisearch-php (^1.0).'
        );
    }

    /**
     * Create a collection engine instance.
     */
    public function createCollectionDriver(): CollectionEngine
    {
        return new CollectionEngine();
    }

    /**
     * Create a null engine instance.
     */
    public function createNullDriver(): NullEngine
    {
        return new NullEngine();
    }

    /**
     * Register a custom driver creator.
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Forget all of the resolved engine instances.
     *
     * Primarily useful for testing.
     */
    public function forgetEngines(): static
    {
        self::$engines = [];

        return $this;
    }

    /**
     * Forget a specific resolved engine instance.
     */
    public function forgetEngine(string $name): static
    {
        unset(self::$engines[$name]);

        return $this;
    }

    /**
     * Get the default Scout driver name.
     */
    public function getDefaultDriver(): string
    {
        $driver = $this->getConfig('driver');

        return $driver ?? 'null';
    }

    /**
     * Get a Scout configuration value.
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->container->get(ConfigInterface::class)->get("scout.{$key}", $default);
    }
}
