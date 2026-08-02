<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Hypervel\Config\Repository;
use Hypervel\Scout\Console\IndexCommand;
use Hypervel\Scout\Contracts\UpdatesIndexSettings;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;

class IndexCommandTest extends TestCase
{
    public function testZeroPrimaryKeyReachesTheEngine(): void
    {
        $engine = m::mock(Engine::class);
        $engine->shouldReceive('createIndex')
            ->once()
            ->with('prod_posts', ['primaryKey' => '0']);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn($engine);

        $config = m::mock(Repository::class);
        $config->shouldReceive('string')->with('scout.prefix', '')->andReturn('prod_');

        $command = $this->command('posts', '0');
        $command->shouldReceive('info')->once()->with('Synchronized index ["prod_posts"] successfully.');

        $this->assertSame(0, $command->handle($manager, $config));
    }

    public function testUnsupportedCreationStillAppliesLogicalIndexSettings(): void
    {
        $engine = m::mock(Engine::class . ', ' . UpdatesIndexSettings::class);
        $engine->shouldReceive('createIndex')
            ->once()
            ->with('prod_posts', [])
            ->andThrow(new NotSupportedException);
        $engine->shouldReceive('updateIndexSettings')
            ->once()
            ->with('prod_posts', ['filterableAttributes' => ['status']]);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn($engine);

        $config = m::mock(Repository::class);
        $config->shouldReceive('string')->with('scout.prefix', '')->andReturn('prod_');
        $config->shouldReceive('string')->with('scout.driver')->andReturn('meilisearch');
        $config->shouldReceive('get')
            ->with('scout.meilisearch.index-settings.posts')
            ->andReturn(['filterableAttributes' => ['status']]);

        $command = $this->command('posts');
        $command->shouldReceive('info')->once()->with('Synchronized index ["prod_posts"] successfully.');

        $this->assertSame(0, $command->handle($manager, $config));
    }

    public function testPhysicalIndexSettingsAreUsedAsFallback(): void
    {
        $engine = m::mock(Engine::class . ', ' . UpdatesIndexSettings::class);
        $engine->shouldReceive('createIndex')->once()->with('prod_posts', []);
        $engine->shouldReceive('updateIndexSettings')
            ->once()
            ->with('prod_posts', ['sortableAttributes' => ['created_at']]);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn($engine);

        $config = m::mock(Repository::class);
        $config->shouldReceive('string')->with('scout.prefix', '')->andReturn('prod_');
        $config->shouldReceive('string')->with('scout.driver')->andReturn('meilisearch');
        $config->shouldReceive('get')->with('scout.meilisearch.index-settings.posts')->andReturn(null);
        $config->shouldReceive('get')
            ->with('scout.meilisearch.index-settings.prod_posts')
            ->andReturn(['sortableAttributes' => ['created_at']]);

        $command = $this->command('posts');
        $command->shouldReceive('info')->once()->with('Synchronized index ["prod_posts"] successfully.');

        $this->assertSame(0, $command->handle($manager, $config));
    }

    public function testOperationalCreationFailuresPropagate(): void
    {
        $failure = new RuntimeException('Search service unavailable.');
        $engine = m::mock(Engine::class);
        $engine->shouldReceive('createIndex')->once()->andThrow($failure);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')->once()->andReturn($engine);

        $config = m::mock(Repository::class);
        $config->shouldReceive('string')->with('scout.prefix', '')->andReturn('');

        $command = $this->command('posts');
        $command->shouldNotReceive('info');

        try {
            $command->handle($manager, $config);
            $this->fail('The operational failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    protected function command(string $name, ?string $key = null): IndexCommand
    {
        $command = m::mock(IndexCommand::class)->makePartial();
        $command->shouldReceive('argument')->with('name')->andReturn($name);
        $command->shouldReceive('option')->with('key')->andReturn($key);

        return $command;
    }
}
