<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaSearchClient;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Contracts\EngineOperationObserver;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\EngineOperationRunner;
use Hypervel\Scout\Engines\AlgoliaEngine;
use Hypervel\Scout\Engines\CollectionEngine;
use Hypervel\Scout\Engines\DatabaseEngine;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Engines\MeilisearchEngine;
use Hypervel\Scout\Engines\NullEngine;
use Hypervel\Scout\Engines\TurbopufferEngine;
use Hypervel\Scout\Engines\TypesenseEngine;
use Hypervel\Scout\Services\Turbopuffer\TurbopufferClient;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Meilisearch\Client as MeilisearchClient;
use Typesense\Client as TypesenseClient;

class EngineManagerTest extends TestCase
{
    use VerifiesDoubles;

    public function testResolveNullEngine(): void
    {
        $container = $this->createContainer(['driver' => 'null']);

        $manager = $this->createManager($container);
        $engine = $manager->engine('null');

        $this->assertInstanceOf(NullEngine::class, $engine);
    }

    public function testResolveCollectionEngine(): void
    {
        $container = $this->createContainer(['driver' => 'collection']);

        $manager = $this->createManager($container);
        $engine = $manager->engine('collection');

        $this->assertInstanceOf(CollectionEngine::class, $engine);
    }

    public function testResolveAlgoliaEngine(): void
    {
        $container = $this->createContainer([
            'driver' => 'algolia',
            'soft_delete' => false,
            'identify' => false,
        ]);

        $algoliaClient = Double::for(AlgoliaSearchClient::class)->strict();
        $container->instance(AlgoliaSearchClient::class, $algoliaClient);

        $manager = $this->createManager($container);
        $engine = $manager->engine('algolia');

        $this->assertInstanceOf(AlgoliaEngine::class, $engine);
    }

    public function testResolveAlgoliaEngineWithSoftDelete(): void
    {
        $container = $this->createContainer([
            'driver' => 'algolia',
            'soft_delete' => true,
            'identify' => false,
        ]);

        $algoliaClient = Double::for(AlgoliaSearchClient::class)->strict();
        $container->instance(AlgoliaSearchClient::class, $algoliaClient);

        $manager = $this->createManager($container);
        $engine = $manager->engine('algolia');

        $this->assertInstanceOf(AlgoliaEngine::class, $engine);
        $this->assertTrue((new ClassInvoker($engine))->softDelete);
    }

    public function testResolveAlgoliaEngineWithIdentify(): void
    {
        $container = $this->createContainer([
            'driver' => 'algolia',
            'soft_delete' => false,
            'identify' => true,
        ]);

        $algoliaClient = Double::for(AlgoliaSearchClient::class)->strict();
        $container->instance(AlgoliaSearchClient::class, $algoliaClient);

        $manager = $this->createManager($container);
        $engine = $manager->engine('algolia');

        $this->assertInstanceOf(AlgoliaEngine::class, $engine);
        $this->assertTrue((new ClassInvoker($engine))->identify);
    }

    public function testResolveMeilisearchEngine(): void
    {
        $meilisearchConfig = [
            'model-settings' => [
                Model::class => ['embedding' => ['embedder' => 'default', 'dimensions' => 2]],
            ],
        ];

        $container = $this->createContainer([
            'driver' => 'meilisearch',
            'soft_delete' => false,
            'meilisearch' => $meilisearchConfig,
        ]);

        $meilisearchClient = Double::for(MeilisearchClient::class)->strict();
        $container->instance(MeilisearchClient::class, $meilisearchClient);

        $manager = $this->createManager($container);
        $engine = $manager->engine('meilisearch');

        $this->assertInstanceOf(MeilisearchEngine::class, $engine);
        $this->assertSame($meilisearchConfig, (new ClassInvoker($engine))->config);
    }

    public function testResolveMeilisearchEngineWithSoftDelete(): void
    {
        $container = $this->createContainer([
            'driver' => 'meilisearch',
            'soft_delete' => true,
        ]);

        $meilisearchClient = Double::for(MeilisearchClient::class)->strict();
        $container->instance(MeilisearchClient::class, $meilisearchClient);

        $manager = $this->createManager($container);
        $engine = $manager->engine('meilisearch');

        $this->assertInstanceOf(MeilisearchEngine::class, $engine);
        $this->assertTrue((new ClassInvoker($engine))->softDelete);
    }

    public function testResolveDatabaseEngine(): void
    {
        $container = $this->createContainer(['driver' => 'database']);

        $manager = $this->createManager($container);
        $engine = $manager->engine('database');

        $this->assertInstanceOf(DatabaseEngine::class, $engine);
    }

    public function testResolveTypesenseEngine(): void
    {
        $typesenseConfig = [
            'model-settings' => [
                Model::class => ['embedding' => ['attribute' => 'embedding', 'dimensions' => 2]],
            ],
        ];

        $container = $this->createContainer([
            'driver' => 'typesense',
            'soft_delete' => false,
            'typesense' => $typesenseConfig,
        ]);

        $typesenseClient = Double::for(TypesenseClient::class)->strict();
        $container->instance(TypesenseClient::class, $typesenseClient);

        $manager = $this->createManager($container);
        $engine = $manager->engine('typesense');

        $this->assertInstanceOf(TypesenseEngine::class, $engine);
        $this->assertSame($typesenseConfig, (new ClassInvoker($engine))->config);
    }

    public function testResolveTurbopufferEngine(): void
    {
        $turbopufferConfig = [
            'model-settings' => [
                Model::class => ['searchable-attributes' => ['name' => 1]],
            ],
        ];

        $container = $this->createContainer([
            'driver' => 'turbopuffer',
            'soft_delete' => true,
            'turbopuffer' => $turbopufferConfig,
        ]);

        $turbopufferClient = Double::for(TurbopufferClient::class)->strict();
        $container->instance(TurbopufferClient::class, $turbopufferClient);

        $manager = $this->createManager($container);
        $engine = $manager->engine('turbopuffer');

        $this->assertInstanceOf(TurbopufferEngine::class, $engine);
        $this->assertSame($turbopufferClient, (new ClassInvoker($engine))->turbopuffer);
        $this->assertSame($turbopufferConfig, (new ClassInvoker($engine))->config);
        $this->assertTrue((new ClassInvoker($engine))->softDelete);
    }

    public function testEngineUsesDefaultDriver(): void
    {
        $container = $this->createContainer(['driver' => 'collection']);

        $manager = $this->createManager($container);
        $engine = $manager->engine(); // No name specified

        $this->assertInstanceOf(CollectionEngine::class, $engine);
    }

    public function testEngineDefaultsToNullWhenNoDriverConfigured(): void
    {
        $container = $this->createContainer(['driver' => null]);

        $manager = $this->createManager($container);
        $engine = $manager->engine();

        $this->assertInstanceOf(NullEngine::class, $engine);
    }

    public function testEngineCachesInstances(): void
    {
        $container = $this->createContainer(['driver' => 'collection']);

        $manager = $this->createManager($container);

        $engine1 = $manager->engine('collection');
        $engine2 = $manager->engine('collection');

        $this->assertSame($engine1, $engine2);
    }

    public function testForgetEnginesClearsCache(): void
    {
        $container = $this->createContainer(['driver' => 'collection']);

        $manager = $this->createManager($container);

        $engine1 = $manager->engine('collection');
        $manager->forgetEngines();
        $engine2 = $manager->engine('collection');

        $this->assertNotSame($engine1, $engine2);
    }

    public function testForgetEngineClearsSpecificEngine(): void
    {
        $container = $this->createContainer(['driver' => 'collection']);

        $manager = $this->createManager($container);

        $collection1 = $manager->engine('collection');
        $null1 = $manager->engine('null');

        $manager->forgetEngine('collection');

        $collection2 = $manager->engine('collection');
        $null2 = $manager->engine('null');

        $this->assertNotSame($collection1, $collection2);
        $this->assertSame($null1, $null2);
    }

    public function testExtendRegisterCustomDriver(): void
    {
        $container = $this->createContainer(['driver' => 'custom']);

        $customEngine = Double::for(Engine::class)->strict();
        $customEngine->expects('setOperationRunner')
            ->with(Argument::type(EngineOperationRunner::class), 'custom');

        $manager = $this->createManager($container);
        $manager->extend('custom', function ($container) use ($customEngine) {
            return $customEngine;
        });

        $engine = $manager->engine('custom');

        $this->assertSame($customEngine, $engine);
    }

    public function testExtendCustomDriverReceivesContainer(): void
    {
        $container = $this->createContainer(['driver' => 'custom']);

        $receivedContainer = null;
        $customEngine = Double::for(Engine::class)->strict();
        $customEngine->expects('setOperationRunner')
            ->with(Argument::type(EngineOperationRunner::class), 'custom');

        $manager = $this->createManager($container);
        $manager->extend('custom', function ($passedContainer) use (&$receivedContainer, $customEngine) {
            $receivedContainer = $passedContainer;
            return $customEngine;
        });

        $manager->engine('custom');

        $this->assertSame($container, $receivedContainer);
    }

    public function testCustomDriverOverridesBuiltIn(): void
    {
        $container = $this->createContainer(['driver' => 'collection']);

        $customEngine = Double::for(Engine::class)->strict();
        $customEngine->expects('setOperationRunner')
            ->with(Argument::type(EngineOperationRunner::class), 'collection');

        $manager = $this->createManager($container);
        $manager->extend('collection', function () use ($customEngine) {
            return $customEngine;
        });

        $engine = $manager->engine('collection');

        $this->assertSame($customEngine, $engine);
    }

    public function testThrowsExceptionForUnsupportedDriver(): void
    {
        $container = $this->createContainer(['driver' => 'unsupported']);

        $manager = $this->createManager($container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Driver [unsupported] is not supported.');

        $manager->engine('unsupported');
    }

    public function testGetDefaultDriverReturnsConfiguredDriver(): void
    {
        $container = $this->createContainer(['driver' => 'meilisearch']);

        $manager = $this->createManager($container);

        $this->assertSame('meilisearch', $manager->getDefaultDriver());
    }

    public function testGetDefaultDriverReturnsNullWhenNotConfigured(): void
    {
        $container = $this->createContainer([]);

        $manager = $this->createManager($container);

        $this->assertSame('null', $manager->getDefaultDriver());
    }

    public function testGetDefaultDriverReturnsNullWhenConfiguredAsPhpNull(): void
    {
        $container = $this->createContainer(['driver' => null]);

        $manager = $this->createManager($container);

        $this->assertSame('null', $manager->getDefaultDriver());
    }

    public function testSeparateManagerInstancesDoNotShareEngineCache(): void
    {
        $container = $this->createContainer(['driver' => 'collection']);

        $manager1 = $this->createManager($container);
        $engine1 = $manager1->engine('collection');

        $manager2 = $this->createManager($container);
        $engine2 = $manager2->engine('collection');

        $this->assertNotSame($engine1, $engine2);
    }

    public function testDefaultNullEngineDoesNotNotifyOperationObservers(): void
    {
        $container = $this->createContainer([]);
        $runner = new EngineOperationRunner;
        $observer = Double::for(EngineOperationObserver::class)->strict();
        $observer->expects('starting')->never();
        $observer->expects('finished')->never();
        $runner->observe($observer);
        $manager = new EngineManager($container, $runner);

        $result = $manager->engine()->runSearch(new Builder(Double::for(Model::class)->strict(), 'query'));

        $this->assertSame([], $result);
    }

    /**
     * Create a manager with an independent operation runner.
     */
    protected function createManager(Container $container): EngineManager
    {
        return new EngineManager($container, new EngineOperationRunner);
    }

    /**
     * Create a container with the given Scout configuration.
     */
    protected function createContainer(array $config): Container
    {
        $container = new Container;
        $container->instance('config', new Repository([
            'scout' => ['soft_delete' => false, 'identify' => false, ...$config],
        ]));

        return $container;
    }
}
