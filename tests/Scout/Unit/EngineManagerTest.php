<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaSearchClient;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
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
use Hypervel\Scout\Engines\TypesenseEngine;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Meilisearch\Client as MeilisearchClient;
use Mockery as m;
use Typesense\Client as TypesenseClient;

class EngineManagerTest extends TestCase
{
    public function testResolveNullEngine(): void
    {
        $container = $this->createMockContainer(['driver' => 'null']);

        $manager = $this->createManager($container);
        $engine = $manager->engine('null');

        $this->assertInstanceOf(NullEngine::class, $engine);
    }

    public function testResolveCollectionEngine(): void
    {
        $container = $this->createMockContainer(['driver' => 'collection']);

        $manager = $this->createManager($container);
        $engine = $manager->engine('collection');

        $this->assertInstanceOf(CollectionEngine::class, $engine);
    }

    public function testResolveAlgoliaEngine(): void
    {
        $container = $this->createMockContainerWithAlgolia([
            'driver' => 'algolia',
            'soft_delete' => false,
            'identify' => false,
        ]);

        $algoliaClient = m::mock(AlgoliaSearchClient::class);
        $container->shouldReceive('make')
            ->with(AlgoliaSearchClient::class)
            ->andReturn($algoliaClient);

        $manager = $this->createManager($container);
        $engine = $manager->engine('algolia');

        $this->assertInstanceOf(AlgoliaEngine::class, $engine);
    }

    public function testResolveAlgoliaEngineWithSoftDelete(): void
    {
        $container = $this->createMockContainerWithAlgolia([
            'driver' => 'algolia',
            'soft_delete' => true,
            'identify' => false,
        ]);

        $algoliaClient = m::mock(AlgoliaSearchClient::class);
        $container->shouldReceive('make')
            ->with(AlgoliaSearchClient::class)
            ->andReturn($algoliaClient);

        $manager = $this->createManager($container);
        $engine = $manager->engine('algolia');

        $this->assertInstanceOf(AlgoliaEngine::class, $engine);
        $this->assertTrue((new ClassInvoker($engine))->softDelete);
    }

    public function testResolveAlgoliaEngineWithIdentify(): void
    {
        $container = $this->createMockContainerWithAlgolia([
            'driver' => 'algolia',
            'soft_delete' => false,
            'identify' => true,
        ]);

        $algoliaClient = m::mock(AlgoliaSearchClient::class);
        $container->shouldReceive('make')
            ->with(AlgoliaSearchClient::class)
            ->andReturn($algoliaClient);

        $manager = $this->createManager($container);
        $engine = $manager->engine('algolia');

        $this->assertInstanceOf(AlgoliaEngine::class, $engine);
        $this->assertTrue((new ClassInvoker($engine))->identify);
    }

    public function testResolveMeilisearchEngine(): void
    {
        $container = $this->createMockContainer([
            'driver' => 'meilisearch',
            'soft_delete' => false,
        ]);

        $meilisearchClient = m::mock(MeilisearchClient::class);
        $container->shouldReceive('make')
            ->with(MeilisearchClient::class)
            ->andReturn($meilisearchClient);

        $manager = $this->createManager($container);
        $engine = $manager->engine('meilisearch');

        $this->assertInstanceOf(MeilisearchEngine::class, $engine);
    }

    public function testResolveMeilisearchEngineWithSoftDelete(): void
    {
        $container = $this->createMockContainer([
            'driver' => 'meilisearch',
            'soft_delete' => true,
        ]);

        $meilisearchClient = m::mock(MeilisearchClient::class);
        $container->shouldReceive('make')
            ->with(MeilisearchClient::class)
            ->andReturn($meilisearchClient);

        $manager = $this->createManager($container);
        $engine = $manager->engine('meilisearch');

        $this->assertInstanceOf(MeilisearchEngine::class, $engine);
    }

    public function testResolveDatabaseEngine(): void
    {
        $container = $this->createMockContainer(['driver' => 'database']);

        $manager = $this->createManager($container);
        $engine = $manager->engine('database');

        $this->assertInstanceOf(DatabaseEngine::class, $engine);
    }

    public function testResolveTypesenseEngine(): void
    {
        $container = $this->createMockContainerWithTypesense([
            'driver' => 'typesense',
            'soft_delete' => false,
        ]);

        $typesenseClient = m::mock(TypesenseClient::class);
        $container->shouldReceive('make')
            ->with(TypesenseClient::class)
            ->andReturn($typesenseClient);

        $manager = $this->createManager($container);
        $engine = $manager->engine('typesense');

        $this->assertInstanceOf(TypesenseEngine::class, $engine);
    }

    public function testEngineUsesDefaultDriver(): void
    {
        $container = $this->createMockContainer(['driver' => 'collection']);

        $manager = $this->createManager($container);
        $engine = $manager->engine(); // No name specified

        $this->assertInstanceOf(CollectionEngine::class, $engine);
    }

    public function testEngineDefaultsToNullWhenNoDriverConfigured(): void
    {
        $container = $this->createMockContainer(['driver' => null]);

        $manager = $this->createManager($container);
        $engine = $manager->engine();

        $this->assertInstanceOf(NullEngine::class, $engine);
    }

    public function testEngineCachesInstances(): void
    {
        $container = $this->createMockContainer(['driver' => 'collection']);

        $manager = $this->createManager($container);

        $engine1 = $manager->engine('collection');
        $engine2 = $manager->engine('collection');

        $this->assertSame($engine1, $engine2);
    }

    public function testForgetEnginesClearsCache(): void
    {
        $container = $this->createMockContainer(['driver' => 'collection']);

        $manager = $this->createManager($container);

        $engine1 = $manager->engine('collection');
        $manager->forgetEngines();
        $engine2 = $manager->engine('collection');

        $this->assertNotSame($engine1, $engine2);
    }

    public function testForgetEngineClearsSpecificEngine(): void
    {
        $container = $this->createMockContainer(['driver' => 'collection']);

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
        $container = $this->createMockContainer(['driver' => 'custom']);

        $customEngine = m::mock(Engine::class);
        $customEngine->shouldReceive('setOperationRunner')
            ->once()
            ->with(m::type(EngineOperationRunner::class), 'custom')
            ->andReturnSelf();

        $manager = $this->createManager($container);
        $manager->extend('custom', function ($container) use ($customEngine) {
            return $customEngine;
        });

        $engine = $manager->engine('custom');

        $this->assertSame($customEngine, $engine);
    }

    public function testExtendCustomDriverReceivesContainer(): void
    {
        $container = $this->createMockContainer(['driver' => 'custom']);

        $receivedContainer = null;
        $customEngine = m::mock(Engine::class);
        $customEngine->shouldReceive('setOperationRunner')
            ->once()
            ->with(m::type(EngineOperationRunner::class), 'custom')
            ->andReturnSelf();

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
        $container = $this->createMockContainer(['driver' => 'collection']);

        $customEngine = m::mock(Engine::class);
        $customEngine->shouldReceive('setOperationRunner')
            ->once()
            ->with(m::type(EngineOperationRunner::class), 'collection')
            ->andReturnSelf();

        $manager = $this->createManager($container);
        $manager->extend('collection', function () use ($customEngine) {
            return $customEngine;
        });

        $engine = $manager->engine('collection');

        $this->assertSame($customEngine, $engine);
    }

    public function testThrowsExceptionForUnsupportedDriver(): void
    {
        $container = $this->createMockContainer(['driver' => 'unsupported']);

        $manager = $this->createManager($container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver [unsupported] is not supported.');

        $manager->engine('unsupported');
    }

    public function testGetDefaultDriverReturnsConfiguredDriver(): void
    {
        $container = $this->createMockContainer(['driver' => 'meilisearch']);

        $manager = $this->createManager($container);

        $this->assertSame('meilisearch', $manager->getDefaultDriver());
    }

    public function testGetDefaultDriverReturnsNullWhenNotConfigured(): void
    {
        $container = $this->createMockContainer([]);

        $manager = $this->createManager($container);

        $this->assertSame('null', $manager->getDefaultDriver());
    }

    public function testGetDefaultDriverReturnsNullWhenConfiguredAsPhpNull(): void
    {
        $container = $this->createMockContainer(['driver' => null]);

        $manager = $this->createManager($container);

        $this->assertSame('null', $manager->getDefaultDriver());
    }

    public function testSeparateManagerInstancesDoNotShareEngineCache(): void
    {
        $container = $this->createMockContainer(['driver' => 'collection']);

        $manager1 = $this->createManager($container);
        $engine1 = $manager1->engine('collection');

        $manager2 = $this->createManager($container);
        $engine2 = $manager2->engine('collection');

        $this->assertNotSame($engine1, $engine2);
    }

    public function testDefaultNullEngineDoesNotNotifyOperationObservers(): void
    {
        $container = $this->createMockContainer([]);
        $runner = new EngineOperationRunner;
        $observer = m::mock(EngineOperationObserver::class);
        $observer->shouldNotReceive('starting');
        $observer->shouldNotReceive('finished');
        $runner->observe($observer);
        $manager = new EngineManager($container, $runner);

        $result = $manager->engine()->runSearch(new Builder(m::mock(Model::class), 'query'));

        $this->assertSame([], $result);
    }

    protected function createManager(Container $container): EngineManager
    {
        return new EngineManager($container, new EngineOperationRunner);
    }

    protected function createMockContainer(array $config): m\MockInterface&Container
    {
        $container = m::mock(Container::class);

        $configService = m::mock(Repository::class);
        $configService->shouldReceive('get')
            ->with('scout.driver', m::any())
            ->andReturn($config['driver'] ?? null);
        $configService->shouldReceive('boolean')
            ->with('scout.soft_delete')
            ->andReturn($config['soft_delete'] ?? false);

        $container->shouldReceive('make')
            ->with('config')
            ->andReturn($configService);

        return $container;
    }

    protected function createMockContainerWithTypesense(array $config): m\MockInterface&Container
    {
        $container = m::mock(Container::class);

        $configService = m::mock(Repository::class);
        $configService->shouldReceive('get')
            ->with('scout.driver', m::any())
            ->andReturn($config['driver'] ?? null);
        $configService->shouldReceive('boolean')
            ->with('scout.soft_delete')
            ->andReturn($config['soft_delete'] ?? false);
        $configService->shouldReceive('integer')
            ->with('scout.typesense.max_total_results', m::any())
            ->andReturn($config['max_total_results'] ?? 1000);

        $container->shouldReceive('make')
            ->with('config')
            ->andReturn($configService);

        return $container;
    }

    protected function createMockContainerWithAlgolia(array $config): m\MockInterface&Container
    {
        $container = m::mock(Container::class);

        $configService = m::mock(Repository::class);
        $configService->shouldReceive('get')
            ->with('scout.driver', m::any())
            ->andReturn($config['driver'] ?? null);
        $configService->shouldReceive('boolean')
            ->with('scout.soft_delete')
            ->andReturn($config['soft_delete'] ?? false);
        $configService->shouldReceive('boolean')
            ->with('scout.identify')
            ->andReturn($config['identify'] ?? false);

        $container->shouldReceive('make')
            ->with('config')
            ->andReturn($configService);

        return $container;
    }
}
