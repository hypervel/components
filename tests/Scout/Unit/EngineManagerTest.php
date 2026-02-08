<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Config\Repository;
use Hypervel\Scout\Engine;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\CollectionEngine;
use Hypervel\Scout\Engines\DatabaseEngine;
use Hypervel\Scout\Engines\MeilisearchEngine;
use Hypervel\Scout\Engines\NullEngine;
use Hypervel\Scout\Engines\TypesenseEngine;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Meilisearch\Client as MeilisearchClient;
use Mockery as m;
use Psr\Container\ContainerInterface;
use Typesense\Client as TypesenseClient;

/**
 * @internal
 * @coversNothing
 */
class EngineManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        // Reset static engines cache between tests
        (new EngineManager(m::mock(ContainerInterface::class)))->forgetEngines();
    }

    public function testResolveNullEngine()
    {
        $container = $this->createMockContainer(['driver' => 'null']);

        $manager = new EngineManager($container);
        $engine = $manager->engine('null');

        $this->assertInstanceOf(NullEngine::class, $engine);
    }

    public function testResolveCollectionEngine()
    {
        $container = $this->createMockContainer(['driver' => 'collection']);

        $manager = new EngineManager($container);
        $engine = $manager->engine('collection');

        $this->assertInstanceOf(CollectionEngine::class, $engine);
    }

    public function testResolveMeilisearchEngine()
    {
        $container = $this->createMockContainer([
            'driver' => 'meilisearch',
            'soft_delete' => false,
        ]);

        $meilisearchClient = m::mock(MeilisearchClient::class);
        $container->shouldReceive('get')
            ->with(MeilisearchClient::class)
            ->andReturn($meilisearchClient);

        $manager = new EngineManager($container);
        $engine = $manager->engine('meilisearch');

        $this->assertInstanceOf(MeilisearchEngine::class, $engine);
    }

    public function testResolveMeilisearchEngineWithSoftDelete()
    {
        $container = $this->createMockContainer([
            'driver' => 'meilisearch',
            'soft_delete' => true,
        ]);

        $meilisearchClient = m::mock(MeilisearchClient::class);
        $container->shouldReceive('get')
            ->with(MeilisearchClient::class)
            ->andReturn($meilisearchClient);

        $manager = new EngineManager($container);
        $engine = $manager->engine('meilisearch');

        $this->assertInstanceOf(MeilisearchEngine::class, $engine);
    }

    public function testResolveDatabaseEngine()
    {
        $container = $this->createMockContainer(['driver' => 'database']);

        $manager = new EngineManager($container);
        $engine = $manager->engine('database');

        $this->assertInstanceOf(DatabaseEngine::class, $engine);
    }

    public function testResolveTypesenseEngine()
    {
        $container = $this->createMockContainerWithTypesense([
            'driver' => 'typesense',
            'soft_delete' => false,
        ]);

        $typesenseClient = m::mock(TypesenseClient::class);
        $container->shouldReceive('get')
            ->with(TypesenseClient::class)
            ->andReturn($typesenseClient);

        $manager = new EngineManager($container);
        $engine = $manager->engine('typesense');

        $this->assertInstanceOf(TypesenseEngine::class, $engine);
    }

    public function testEngineUsesDefaultDriver()
    {
        $container = $this->createMockContainer(['driver' => 'collection']);

        $manager = new EngineManager($container);
        $engine = $manager->engine(); // No name specified

        $this->assertInstanceOf(CollectionEngine::class, $engine);
    }

    public function testEngineDefaultsToNullWhenNoDriverConfigured()
    {
        $container = $this->createMockContainer(['driver' => null]);

        $manager = new EngineManager($container);
        $engine = $manager->engine();

        $this->assertInstanceOf(NullEngine::class, $engine);
    }

    public function testEngineCachesInstances()
    {
        $container = $this->createMockContainer(['driver' => 'collection']);

        $manager = new EngineManager($container);

        $engine1 = $manager->engine('collection');
        $engine2 = $manager->engine('collection');

        $this->assertSame($engine1, $engine2);
    }

    public function testForgetEnginesClearsCache()
    {
        $container = $this->createMockContainer(['driver' => 'collection']);

        $manager = new EngineManager($container);

        $engine1 = $manager->engine('collection');
        $manager->forgetEngines();
        $engine2 = $manager->engine('collection');

        $this->assertNotSame($engine1, $engine2);
    }

    public function testForgetEngineClearsSpecificEngine()
    {
        $container = $this->createMockContainer(['driver' => 'collection']);

        $manager = new EngineManager($container);

        $collection1 = $manager->engine('collection');
        $null1 = $manager->engine('null');

        $manager->forgetEngine('collection');

        $collection2 = $manager->engine('collection');
        $null2 = $manager->engine('null');

        $this->assertNotSame($collection1, $collection2);
        $this->assertSame($null1, $null2);
    }

    public function testExtendRegisterCustomDriver()
    {
        $container = $this->createMockContainer(['driver' => 'custom']);

        $customEngine = m::mock(Engine::class);

        $manager = new EngineManager($container);
        $manager->extend('custom', function ($container) use ($customEngine) {
            return $customEngine;
        });

        $engine = $manager->engine('custom');

        $this->assertSame($customEngine, $engine);
    }

    public function testExtendCustomDriverReceivesContainer()
    {
        $container = $this->createMockContainer(['driver' => 'custom']);

        $receivedContainer = null;
        $customEngine = m::mock(Engine::class);

        $manager = new EngineManager($container);
        $manager->extend('custom', function ($passedContainer) use (&$receivedContainer, $customEngine) {
            $receivedContainer = $passedContainer;
            return $customEngine;
        });

        $manager->engine('custom');

        $this->assertSame($container, $receivedContainer);
    }

    public function testCustomDriverOverridesBuiltIn()
    {
        $container = $this->createMockContainer(['driver' => 'collection']);

        $customEngine = m::mock(Engine::class);

        $manager = new EngineManager($container);
        $manager->extend('collection', function () use ($customEngine) {
            return $customEngine;
        });

        $engine = $manager->engine('collection');

        $this->assertSame($customEngine, $engine);
    }

    public function testThrowsExceptionForUnsupportedDriver()
    {
        $container = $this->createMockContainer(['driver' => 'unsupported']);

        $manager = new EngineManager($container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver [unsupported] is not supported.');

        $manager->engine('unsupported');
    }

    public function testGetDefaultDriverReturnsConfiguredDriver()
    {
        $container = $this->createMockContainer(['driver' => 'meilisearch']);

        $manager = new EngineManager($container);

        $this->assertSame('meilisearch', $manager->getDefaultDriver());
    }

    public function testGetDefaultDriverReturnsNullWhenNotConfigured()
    {
        $container = $this->createMockContainer(['driver' => null]);

        $manager = new EngineManager($container);

        $this->assertSame('null', $manager->getDefaultDriver());
    }

    public function testStaticCacheIsSharedAcrossInstances()
    {
        $container = $this->createMockContainer(['driver' => 'collection']);

        $manager1 = new EngineManager($container);
        $engine1 = $manager1->engine('collection');

        $manager2 = new EngineManager($container);
        $engine2 = $manager2->engine('collection');

        // Static cache means same instance
        $this->assertSame($engine1, $engine2);
    }

    protected function createMockContainer(array $config): m\MockInterface&ContainerInterface
    {
        $container = m::mock(ContainerInterface::class);

        $configService = m::mock(Repository::class);
        $configService->shouldReceive('get')
            ->with('scout.driver', m::any())
            ->andReturn($config['driver'] ?? null);
        $configService->shouldReceive('get')
            ->with('scout.soft_delete', m::any())
            ->andReturn($config['soft_delete'] ?? false);

        $container->shouldReceive('get')
            ->with('config')
            ->andReturn($configService);

        return $container;
    }

    protected function createMockContainerWithTypesense(array $config): m\MockInterface&ContainerInterface
    {
        $container = m::mock(ContainerInterface::class);

        $configService = m::mock(Repository::class);
        $configService->shouldReceive('get')
            ->with('scout.driver', m::any())
            ->andReturn($config['driver'] ?? null);
        $configService->shouldReceive('get')
            ->with('scout.soft_delete', m::any())
            ->andReturn($config['soft_delete'] ?? false);
        $configService->shouldReceive('get')
            ->with('scout.typesense.max_total_results', m::any())
            ->andReturn($config['max_total_results'] ?? 1000);

        $container->shouldReceive('get')
            ->with('config')
            ->andReturn($configService);

        return $container;
    }
}
