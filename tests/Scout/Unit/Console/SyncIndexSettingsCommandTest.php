<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Scout\Console\SyncIndexSettingsCommand;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\EngineOperationRunner;
use Hypervel\Scout\Engines\CollectionEngine;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Scout;
use Hypervel\Tests\Scout\Fixtures\SettingsEngine;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use ReflectionMethod;

class SyncIndexSettingsCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testFailsWhenEngineDoesNotSupportUpdatingIndexSettings(): void
    {
        $engine = new CollectionEngine;

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('getDefaultDriver')
            ->returns('collection');
        $manager->expects('engine')
            ->with('collection')
            ->returns($engine);

        $config = new Repository;

        $command = Double::for(new SyncIndexSettingsCommand)->passthru();
        $command->allows('option')
            ->with('driver')
            ->returns(null);
        $command->expects('error')
            ->with('The "collection" engine does not support updating index settings.');

        $result = $command->handle($manager, $config);

        $this->assertSame(1, $result);
    }

    public function testNullConfiguredDriverResolvesThroughTheManager(): void
    {
        $config = new Repository(['scout' => ['driver' => null]]);
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with('config')->returns($config);
        $manager = new EngineManager($container->instance(), new EngineOperationRunner);

        $command = Double::for(new SyncIndexSettingsCommand)->passthru();
        $command->allows('option')->with('driver')->returns(null);
        $command->expects('error')
            ->with('The "null" engine does not support updating index settings.');

        $this->assertSame(1, $command->handle($manager, $config));
    }

    public function testSucceedsWithInfoMessageWhenNoIndexSettingsConfigured(): void
    {
        $engine = Double::for(SettingsEngine::class)->strict();

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('getDefaultDriver')
            ->returns('meilisearch');
        $manager->expects('engine')
            ->with('meilisearch')
            ->returns($engine);

        $config = new Repository(['scout' => ['meilisearch' => ['index-settings' => []]]]);

        $command = Double::for(new SyncIndexSettingsCommand)->passthru();
        $command->allows('option')
            ->with('driver')
            ->returns(null);
        $command->expects('info')
            ->with('No index settings found for the "meilisearch" engine.');

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    public function testSyncsIndexSettingsSuccessfully(): void
    {
        $engine = Double::for(SettingsEngine::class)->strict();
        $engine->expects('updateIndexSettings')
            ->with('test_posts', ['filterableAttributes' => ['status']]);

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('getDefaultDriver')
            ->returns('meilisearch');
        $manager->expects('engine')
            ->with('meilisearch')
            ->returns($engine);

        $config = new Repository(['scout' => [
            'prefix' => '',
            'meilisearch' => ['index-settings' => [
                'test_posts' => ['filterableAttributes' => ['status']],
            ]],
        ]]);

        $command = Double::for(new SyncIndexSettingsCommand)->passthru();
        $command->allows('option')
            ->with('driver')
            ->returns(null);
        $command->expects('info')
            ->with('Settings for the [test_posts] index synced successfully.');

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    public function testLifecycleCallbackReceivesModelSettingsAfterSoftDeleteContribution(): void
    {
        $engine = Double::for(SettingsEngine::class)->strict();
        $engine->expects('configureSoftDeleteFilter')
            ->with(['searchableAttributes' => ['title']])
            ->returns([
                'searchableAttributes' => ['title'],
                'filterableAttributes' => ['__soft_deleted'],
            ]);
        $engine->expects('updateIndexSettings')
            ->with('soft_deletable_searchable_models', [
                'searchableAttributes' => ['title'],
                'filterableAttributes' => ['__soft_deleted', 'tenant_id'],
            ]);
        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('getDefaultDriver')->returns('meilisearch');
        $manager->expects('engine')->with('meilisearch')->returns($engine);
        $config = new Repository(['scout' => [
            'soft_delete' => true,
            'meilisearch' => ['index-settings' => [
                SyncIndexSettingsSoftDeleteModel::class => ['searchableAttributes' => ['title']],
            ]],
        ]]);

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

        $command = Double::for(new SyncIndexSettingsCommand)->passthru();
        $command->allows('option')->with('driver')->returns(null);
        $command->expects('info');

        $this->assertSame(0, $command->handle($manager, $config));
    }

    public function testUsesDriverOptionWhenProvided(): void
    {
        $engine = Double::for(SettingsEngine::class)->strict();

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')
            ->with('typesense')
            ->returns($engine);

        $config = new Repository(['scout' => ['typesense' => ['index-settings' => []]]]);

        $command = Double::for(new SyncIndexSettingsCommand)->passthru();
        $command->allows('option')
            ->with('driver')
            ->returns('typesense');
        $command->expects('info')
            ->with('No index settings found for the "typesense" engine.');

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    public function testZeroDriverOptionIsNotReplacedByConfiguredDriver(): void
    {
        $engine = Double::for(SettingsEngine::class)->strict();

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')
            ->with('0')
            ->returns($engine);

        $config = new Repository(['scout' => ['0' => ['index-settings' => []]]]);

        $command = Double::for(new SyncIndexSettingsCommand)->passthru();
        $command->allows('option')
            ->with('driver')
            ->returns('0');
        $command->expects('info')
            ->with('No index settings found for the "0" engine.');

        $this->assertSame(0, $command->handle($manager, $config));
    }

    public function testEmptyDriverOptionUsesConfiguredDriver(): void
    {
        $engine = Double::for(SettingsEngine::class)->strict();

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('getDefaultDriver')
            ->returns('meilisearch');
        $manager->expects('engine')
            ->with('meilisearch')
            ->returns($engine);

        $config = new Repository(['scout' => ['meilisearch' => ['index-settings' => []]]]);

        $command = Double::for(new SyncIndexSettingsCommand)->passthru();
        $command->allows('option')
            ->with('driver')
            ->returns('');
        $command->expects('info')
            ->with('No index settings found for the "meilisearch" engine.');

        $this->assertSame(0, $command->handle($manager, $config));
    }

    public function testIndexNameResolutionPrependsPrefix(): void
    {
        $command = new SyncIndexSettingsCommand;

        $method = new ReflectionMethod(SyncIndexSettingsCommand::class, 'indexName');

        $config = new Repository(['scout' => ['prefix' => 'prod_']]);

        // Test that prefix is prepended when not already present
        $result = $method->invoke($command, 'posts', $config);
        $this->assertSame('prod_posts', $result);
    }

    public function testIndexNameResolutionDoesNotDuplicatePrefix(): void
    {
        $command = new SyncIndexSettingsCommand;

        $method = new ReflectionMethod(SyncIndexSettingsCommand::class, 'indexName');

        $config = new Repository(['scout' => ['prefix' => 'prod_']]);

        // Test that prefix is not duplicated when already present
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
