<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Algolia\AlgoliaSearch\Algolia;
use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaSearchClient;
use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Scout\Engines\AlgoliaEngine;
use Hypervel\Scout\Engines\CollectionEngine;
use Hypervel\Scout\Engines\DatabaseEngine;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Engines\MeilisearchEngine;
use Hypervel\Scout\Engines\NullEngine;
use Hypervel\Scout\Engines\TypesenseEngine;
use InvalidArgumentException;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Meilisearch;
use RuntimeException;
use Typesense\Client as TypesenseClient;

/**
 * Manages search engine instances and driver creation.
 *
 * EngineManager is auto-singletoned by the container, so its instance cache is
 * shared across coroutines for the worker lifetime.
 */
class EngineManager
{
    /**
     * The resolved engine instances.
     *
     * @var array<string, Engine>
     */
    protected array $engines = [];

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
        protected Container $container
    ) {
    }

    /**
     * Get an engine instance by name.
     */
    public function engine(?string $name = null): Engine
    {
        $name ??= $this->getDefaultDriver();

        return $this->engines[$name] ??= $this->resolve($name);
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
     * Create an Algolia engine instance.
     *
     * @throws RuntimeException
     */
    public function createAlgoliaDriver(): AlgoliaEngine
    {
        $this->ensureAlgoliaClientIsInstalled();

        return new AlgoliaEngine(
            $this->container->make(AlgoliaSearchClient::class),
            $this->getConfig('soft_delete', false),
            $this->getConfig('identify', false),
        );
    }

    /**
     * Ensure the Algolia client is installed.
     *
     * @throws RuntimeException
     */
    protected function ensureAlgoliaClientIsInstalled(): void
    {
        if (class_exists(Algolia::class) && version_compare(Algolia::VERSION, '4.0.0', '>=')) {
            return;
        }

        throw new RuntimeException(
            'Please install the Algolia client: algolia/algoliasearch-client-php (^4.0).'
        );
    }

    /**
     * Create a Meilisearch engine instance.
     */
    public function createMeilisearchDriver(): MeilisearchEngine
    {
        $this->ensureMeilisearchClientIsInstalled();

        return new MeilisearchEngine(
            $this->container->make(MeilisearchClient::class),
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
     * Create a Typesense engine instance.
     *
     * @throws RuntimeException
     */
    public function createTypesenseDriver(): TypesenseEngine
    {
        $this->ensureTypesenseClientIsInstalled();

        return new TypesenseEngine(
            $this->container->make(TypesenseClient::class),
            (int) $this->getConfig('typesense.max_total_results', 1000)
        );
    }

    /**
     * Ensure the Typesense client is installed.
     *
     * @throws RuntimeException
     */
    protected function ensureTypesenseClientIsInstalled(): void
    {
        if (class_exists(TypesenseClient::class)) {
            return;
        }

        throw new RuntimeException(
            'Please install the Typesense client: typesense/typesense-php.'
        );
    }

    /**
     * Create a collection engine instance.
     */
    public function createCollectionDriver(): CollectionEngine
    {
        return new CollectionEngine;
    }

    /**
     * Create a database engine instance.
     */
    public function createDatabaseDriver(): DatabaseEngine
    {
        return new DatabaseEngine;
    }

    /**
     * Create a null engine instance.
     */
    public function createNullDriver(): NullEngine
    {
        return new NullEngine;
    }

    /**
     * Register a custom driver creator.
     *
     * Boot-only. The callback persists in the singleton's customCreators array
     * for the worker lifetime and applies to every subsequent engine resolution.
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Forget all of the resolved engine instances.
     *
     * Boot or tests only. Clears the manager's engine cache; concurrent
     * coroutines may already hold references that next resolution will not
     * share.
     */
    public function forgetEngines(): static
    {
        $this->engines = [];

        return $this;
    }

    /**
     * Forget a specific resolved engine instance.
     *
     * Boot or tests only. Mutates the manager's engine cache; concurrent
     * coroutines may already hold a reference to the engine and next resolution
     * will rebuild.
     */
    public function forgetEngine(string $name): static
    {
        unset($this->engines[$name]);

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
        return $this->container->make('config')->get("scout.{$key}", $default);
    }
}
