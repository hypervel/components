<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Exception;
use Hypervel\Config\Repository;
use Hypervel\Scout\Console\DeleteAllIndexesCommand;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\CollectionEngine;
use Hypervel\Scout\Engines\MeilisearchEngine;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DeleteAllIndexesCommandTest extends TestCase
{
    public function testDeletesAllIndexesSuccessfully(): void
    {
        $engine = m::mock(MeilisearchEngine::class);
        $engine->shouldReceive('deleteAllIndexes')
            ->once()
            ->with('test_')
            ->andReturn([]);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->once()
            ->andReturn($engine);

        $config = $this->configWithPrefix('test_');

        $command = m::mock(DeleteAllIndexesCommand::class)->makePartial();
        $command->shouldReceive('option')->with('force')->andReturn(false);
        $command->shouldReceive('info')
            ->once()
            ->with('All indexes deleted successfully.');

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    public function testFailsWhenEngineDoesNotSupportDeleteAllIndexes(): void
    {
        $engine = new CollectionEngine;

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->once()
            ->andReturn($engine);
        $manager->shouldReceive('getDefaultDriver')
            ->once()
            ->andReturn('collection');

        // Must set a non-empty prefix: the safety gate runs BEFORE engine
        // resolution, and with an empty prefix we'd hit the refusal message
        // rather than the "does not support" path.
        $config = $this->configWithPrefix('test_');

        $command = m::mock(DeleteAllIndexesCommand::class)->makePartial();
        $command->shouldReceive('option')->with('force')->andReturn(false);
        $command->shouldReceive('error')
            ->once()
            ->with('The [collection] engine does not support deleting all indexes.');

        $result = $command->handle($manager, $config);

        $this->assertSame(1, $result);
    }

    public function testHandlesExceptionFromEngine(): void
    {
        $engine = m::mock(MeilisearchEngine::class);
        $engine->shouldReceive('deleteAllIndexes')
            ->once()
            ->with('test_')
            ->andThrow(new Exception('Connection failed'));

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->once()
            ->andReturn($engine);

        $config = $this->configWithPrefix('test_');

        $command = m::mock(DeleteAllIndexesCommand::class)->makePartial();
        $command->shouldReceive('option')->with('force')->andReturn(false);
        $command->shouldReceive('error')
            ->once()
            ->with('Connection failed');

        $result = $command->handle($manager, $config);

        $this->assertSame(1, $result);
    }

    public function testRefusesWhenPrefixEmptyAndNotForced(): void
    {
        $manager = m::mock(EngineManager::class);
        // Safety gate runs first; engine is never resolved.
        $manager->shouldNotReceive('engine');

        $config = $this->configWithPrefix('');

        $capturedMessage = null;
        $command = m::mock(DeleteAllIndexesCommand::class)->makePartial();
        $command->shouldReceive('option')->with('force')->andReturn(false);
        $command->shouldReceive('error')
            ->once()
            ->with(m::on(function (string $message) use (&$capturedMessage) {
                $capturedMessage = $message;

                return true;
            }));

        $result = $command->handle($manager, $config);

        $this->assertSame(1, $result);
        $this->assertStringContainsString('scout.prefix', $capturedMessage);
        $this->assertStringContainsString('--force', $capturedMessage);
    }

    public function testRunsUnscopedWhenForcedWithEmptyPrefix(): void
    {
        $engine = m::mock(MeilisearchEngine::class);
        $engine->shouldReceive('deleteAllIndexes')
            ->once()
            ->with(null)
            ->andReturn([]);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->once()
            ->andReturn($engine);

        $config = $this->configWithPrefix('');

        $command = m::mock(DeleteAllIndexesCommand::class)->makePartial();
        $command->shouldReceive('option')->with('force')->andReturn(true);
        $command->shouldReceive('info')->once();

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    public function testRunsScopedWhenPrefixSet(): void
    {
        $engine = m::mock(MeilisearchEngine::class);
        $engine->shouldReceive('deleteAllIndexes')
            ->once()
            ->with('app_')
            ->andReturn([]);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->once()
            ->andReturn($engine);

        $config = $this->configWithPrefix('app_');

        $command = m::mock(DeleteAllIndexesCommand::class)->makePartial();
        $command->shouldReceive('option')->with('force')->andReturn(false);
        $command->shouldReceive('info')->once();

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    public function testForceDoesNotOverrideScopingWhenPrefixSet(): void
    {
        $engine = m::mock(MeilisearchEngine::class);
        // Even with --force, the configured prefix still scopes the deletion.
        $engine->shouldReceive('deleteAllIndexes')
            ->once()
            ->with('app_')
            ->andReturn([]);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->once()
            ->andReturn($engine);

        $config = $this->configWithPrefix('app_');

        $command = m::mock(DeleteAllIndexesCommand::class)->makePartial();
        $command->shouldReceive('option')->with('force')->andReturn(true);
        $command->shouldReceive('info')->once();

        $result = $command->handle($manager, $config);

        $this->assertSame(0, $result);
    }

    protected function configWithPrefix(string $prefix): Repository
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('scout.prefix', '')
            ->andReturn($prefix);

        return $config;
    }
}
