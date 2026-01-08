<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Exception;
use Hypervel\Scout\Console\DeleteAllIndexesCommand;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\CollectionEngine;
use Hypervel\Scout\Engines\MeilisearchEngine;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class DeleteAllIndexesCommandTest extends TestCase
{
    public function testDeletesAllIndexesSuccessfully(): void
    {
        $engine = m::mock(MeilisearchEngine::class);
        $engine->shouldReceive('deleteAllIndexes')
            ->once()
            ->andReturn([]);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->once()
            ->andReturn($engine);

        $command = m::mock(DeleteAllIndexesCommand::class)->makePartial();
        $command->shouldReceive('info')
            ->once()
            ->with('All indexes deleted successfully.');

        $result = $command->handle($manager);

        $this->assertSame(0, $result);
    }

    public function testFailsWhenEngineDoesNotSupportDeleteAllIndexes(): void
    {
        $engine = new CollectionEngine();

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->once()
            ->andReturn($engine);
        $manager->shouldReceive('getDefaultDriver')
            ->once()
            ->andReturn('collection');

        $command = m::mock(DeleteAllIndexesCommand::class)->makePartial();
        $command->shouldReceive('error')
            ->once()
            ->with('The [collection] engine does not support deleting all indexes.');

        $result = $command->handle($manager);

        $this->assertSame(1, $result);
    }

    public function testHandlesExceptionFromEngine(): void
    {
        $engine = m::mock(MeilisearchEngine::class);
        $engine->shouldReceive('deleteAllIndexes')
            ->once()
            ->andThrow(new Exception('Connection failed'));

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')
            ->once()
            ->andReturn($engine);

        $command = m::mock(DeleteAllIndexesCommand::class)->makePartial();
        $command->shouldReceive('error')
            ->once()
            ->with('Connection failed');

        $result = $command->handle($manager);

        $this->assertSame(1, $result);
    }
}
