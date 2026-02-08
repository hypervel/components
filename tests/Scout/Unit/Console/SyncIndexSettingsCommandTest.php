<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Scout\Console\SyncIndexSettingsCommand;
use Hypervel\Scout\Contracts\UpdatesIndexSettings;
use Hypervel\Scout\Engine;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\CollectionEngine;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;

/**
 * @internal
 * @coversNothing
 */
class SyncIndexSettingsCommandTest extends TestCase
{
    public function testFailsWhenEngineDoesNotSupportUpdatingIndexSettings(): void
    {
        $engine = new CollectionEngine();

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->with('collection')
            ->once()
            ->andReturn($engine);

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('scout.driver')
            ->andReturn('collection');

        $command = m::mock(SyncIndexSettingsCommand::class)->makePartial();
        $command->shouldReceive('option')
            ->with('driver')
            ->andReturn(null);
        $command->shouldReceive('error')
            ->once()
            ->with('The "collection" engine does not support updating index settings.');

        $result = $command->handle($manager, $config);

        $this->assertSame(1, $result);
    }

    public function testSucceedsWithInfoMessageWhenNoIndexSettingsConfigured(): void
    {
        $engine = m::mock(Engine::class . ', ' . UpdatesIndexSettings::class);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->with('meilisearch')
            ->once()
            ->andReturn($engine);

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('scout.driver')
            ->andReturn('meilisearch');
        $config->shouldReceive('get')
            ->with('scout.meilisearch.index-settings', [])
            ->andReturn([]);

        $command = m::mock(SyncIndexSettingsCommand::class)->makePartial();
        $command->shouldReceive('option')
            ->with('driver')
            ->andReturn(null);
        $command->shouldReceive('info')
            ->once()
            ->with('No index settings found for the "meilisearch" engine.');

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    public function testSyncsIndexSettingsSuccessfully(): void
    {
        $engine = m::mock(Engine::class . ', ' . UpdatesIndexSettings::class);
        $engine->shouldReceive('updateIndexSettings')
            ->once()
            ->with('test_posts', ['filterableAttributes' => ['status']]);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->with('meilisearch')
            ->once()
            ->andReturn($engine);

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('scout.driver')
            ->andReturn('meilisearch');
        $config->shouldReceive('get')
            ->with('scout.meilisearch.index-settings', [])
            ->andReturn([
                'test_posts' => ['filterableAttributes' => ['status']],
            ]);
        $config->shouldReceive('get')
            ->with('scout.prefix', '')
            ->andReturn('');

        $command = m::mock(SyncIndexSettingsCommand::class)->makePartial();
        $command->shouldReceive('option')
            ->with('driver')
            ->andReturn(null);
        $command->shouldReceive('info')
            ->once()
            ->with('Settings for the [test_posts] index synced successfully.');

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    public function testUsesDriverOptionWhenProvided(): void
    {
        $engine = m::mock(Engine::class . ', ' . UpdatesIndexSettings::class);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->with('typesense')
            ->once()
            ->andReturn($engine);

        $config = m::mock(Repository::class);
        // Note: scout.driver should NOT be called when driver option is provided
        $config->shouldReceive('get')
            ->with('scout.typesense.index-settings', [])
            ->andReturn([]);

        $command = m::mock(SyncIndexSettingsCommand::class)->makePartial();
        $command->shouldReceive('option')
            ->with('driver')
            ->andReturn('typesense');
        $command->shouldReceive('info')
            ->once()
            ->with('No index settings found for the "typesense" engine.');

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    public function testIndexNameResolutionPrependsPrefix(): void
    {
        $command = m::mock(SyncIndexSettingsCommand::class)->makePartial();

        $method = new ReflectionMethod(SyncIndexSettingsCommand::class, 'indexName');
        $method->setAccessible(true);

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('scout.prefix', '')
            ->andReturn('prod_');

        // Test that prefix is prepended when not already present
        $result = $method->invoke($command, 'posts', $config);
        $this->assertSame('prod_posts', $result);
    }

    public function testIndexNameResolutionDoesNotDuplicatePrefix(): void
    {
        $command = m::mock(SyncIndexSettingsCommand::class)->makePartial();

        $method = new ReflectionMethod(SyncIndexSettingsCommand::class, 'indexName');
        $method->setAccessible(true);

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('scout.prefix', '')
            ->andReturn('prod_');

        // Test that prefix is NOT duplicated when already present
        $result = $method->invoke($command, 'prod_posts', $config);
        $this->assertSame('prod_posts', $result);
    }
}
