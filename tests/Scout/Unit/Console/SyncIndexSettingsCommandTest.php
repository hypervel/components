<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Hypervel\Config\Repository;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Console\SyncIndexSettingsCommand;
use Hypervel\Scout\Contracts\UpdatesIndexSettings;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\CollectionEngine;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Scout;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionMethod;

class SyncIndexSettingsCommandTest extends TestCase
{
    public function testFailsWhenEngineDoesNotSupportUpdatingIndexSettings(): void
    {
        $engine = new CollectionEngine;

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->with('collection')
            ->once()
            ->andReturn($engine);

        $config = m::mock(Repository::class);
        $config->shouldReceive('string')
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
        $config->shouldReceive('string')
            ->with('scout.driver')
            ->andReturn('meilisearch');
        $config->shouldReceive('array')
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
        $config->shouldReceive('string')
            ->with('scout.driver')
            ->andReturn('meilisearch');
        $config->shouldReceive('array')
            ->with('scout.meilisearch.index-settings', [])
            ->andReturn([
                'test_posts' => ['filterableAttributes' => ['status']],
            ]);
        $config->shouldReceive('string')
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

    public function testLifecycleCallbackReceivesModelSettingsAfterSoftDeleteContribution(): void
    {
        $engine = m::mock(Engine::class . ', ' . UpdatesIndexSettings::class);
        $engine->shouldReceive('configureSoftDeleteFilter')
            ->once()
            ->with(['searchableAttributes' => ['title']])
            ->andReturn([
                'searchableAttributes' => ['title'],
                'filterableAttributes' => ['__soft_deleted'],
            ]);
        $engine->shouldReceive('updateIndexSettings')
            ->once()
            ->with('soft_deletable_searchable_models', [
                'searchableAttributes' => ['title'],
                'filterableAttributes' => ['__soft_deleted', 'tenant_id'],
            ]);
        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')->with('meilisearch')->once()->andReturn($engine);
        $config = m::mock(Repository::class);
        $config->shouldReceive('string')->with('scout.driver')->andReturn('meilisearch');
        $config->shouldReceive('array')
            ->with('scout.meilisearch.index-settings', [])
            ->andReturn([
                SyncIndexSettingsSoftDeleteModel::class => ['searchableAttributes' => ['title']],
            ]);
        $config->shouldReceive('boolean')->with('scout.soft_delete', false)->andReturn(true);

        Scout::prepareIndexSettingsUsing(function (
            array $settings,
            ?Model $model,
            Engine $givenEngine,
            string $index
        ) use ($engine): array {
            $this->assertInstanceOf(SyncIndexSettingsSoftDeleteModel::class, $model);
            $this->assertSame($engine, $givenEngine);
            $this->assertSame('soft_deletable_searchable_models', $index);
            $this->assertSame([
                'searchableAttributes' => ['title'],
                'filterableAttributes' => ['__soft_deleted'],
            ], $settings);

            $settings['filterableAttributes'][] = 'tenant_id';

            return $settings;
        });

        $command = m::mock(SyncIndexSettingsCommand::class)->makePartial();
        $command->shouldReceive('option')->with('driver')->andReturn(null);
        $command->shouldReceive('info')->once();

        $this->assertSame(0, $command->handle($manager, $config));
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
        $config->shouldReceive('array')
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

    public function testZeroDriverOptionIsNotReplacedByConfiguredDriver(): void
    {
        $engine = m::mock(Engine::class . ', ' . UpdatesIndexSettings::class);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->with('0')
            ->once()
            ->andReturn($engine);

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')
            ->with('scout.0.index-settings', [])
            ->andReturn([]);

        $command = m::mock(SyncIndexSettingsCommand::class)->makePartial();
        $command->shouldReceive('option')
            ->with('driver')
            ->andReturn('0');
        $command->shouldReceive('info')
            ->once()
            ->with('No index settings found for the "0" engine.');

        $this->assertSame(0, $command->handle($manager, $config));
    }

    public function testEmptyDriverOptionUsesConfiguredDriver(): void
    {
        $engine = m::mock(Engine::class . ', ' . UpdatesIndexSettings::class);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->with('meilisearch')
            ->once()
            ->andReturn($engine);

        $config = m::mock(Repository::class);
        $config->shouldReceive('string')
            ->with('scout.driver')
            ->andReturn('meilisearch');
        $config->shouldReceive('array')
            ->with('scout.meilisearch.index-settings', [])
            ->andReturn([]);

        $command = m::mock(SyncIndexSettingsCommand::class)->makePartial();
        $command->shouldReceive('option')
            ->with('driver')
            ->andReturn('');
        $command->shouldReceive('info')
            ->once()
            ->with('No index settings found for the "meilisearch" engine.');

        $this->assertSame(0, $command->handle($manager, $config));
    }

    public function testIndexNameResolutionPrependsPrefix(): void
    {
        $command = m::mock(SyncIndexSettingsCommand::class)->makePartial();

        $method = new ReflectionMethod(SyncIndexSettingsCommand::class, 'indexName');
        $method->setAccessible(true);

        $config = m::mock(Repository::class);
        $config->shouldReceive('string')
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
        $config->shouldReceive('string')
            ->with('scout.prefix', '')
            ->andReturn('prod_');

        // Test that prefix is NOT duplicated when already present
        $result = $method->invoke($command, 'prod_posts', $config);
        $this->assertSame('prod_posts', $result);
    }
}

class SyncIndexSettingsSoftDeleteModel extends Model
{
    use SoftDeletes;

    public function indexableAs(): string
    {
        return 'soft_deletable_searchable_models';
    }
}
