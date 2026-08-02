<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Console\Kernel;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Events\ModelsImported;
use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Jobs\MakeSearchable;
use Hypervel\Scout\Jobs\RemoveFromSearch;
use Hypervel\Scout\Scout;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

class ImportCommandTest extends ScoutTestCase
{
    public function testItThrowsScoutExceptionForNonExistentModelClass(): void
    {
        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Model [NonExistentModel] not found.');

        $this->artisan('scout:import', ['model' => 'NonExistentModel'])->run();
    }

    public function testScoutImportIgnoresQueueEnabledConfigAndRunsSync(): void
    {
        $this->app->make('config')->set('scout.queue.enabled', true);

        for ($i = 1; $i <= 3; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeSearchable::class, RemoveFromSearch::class]);

        $this->artisan('scout:import', ['model' => SearchableModel::class])
            ->expectsOutputToContain('have been imported')
            ->assertSuccessful();

        Bus::assertNotDispatched(MakeSearchable::class);
        Bus::assertNotDispatched(RemoveFromSearch::class);
    }

    public function testScoutImportFreshIgnoresQueueEnabledConfig(): void
    {
        $this->app->make('config')->set('scout.queue.enabled', true);
        $guarded = false;
        Scout::guardModelFlushUsing(function (Model $model, Engine $engine, bool $force) use (&$guarded): void {
            $this->assertInstanceOf(SearchableModel::class, $model);
            $this->assertFalse($force);
            $guarded = true;
        });

        for ($i = 1; $i <= 3; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeSearchable::class, RemoveFromSearch::class]);

        $this->artisan('scout:import', ['model' => SearchableModel::class, '--fresh' => true])
            ->expectsOutputToContain('have been imported')
            ->assertSuccessful();

        Bus::assertNotDispatched(MakeSearchable::class);
        Bus::assertNotDispatched(RemoveFromSearch::class);
        $this->assertTrue($guarded);
    }

    public function testScoutImportDoesNotForgetExistingModelsImportedListeners(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        $eventCount = 0;

        Event::listen(ModelsImported::class, function () use (&$eventCount): void {
            ++$eventCount;
        });

        $this->artisan('scout:import', ['model' => SearchableModel::class])
            ->expectsOutputToContain('have been imported')
            ->assertSuccessful();

        $countAfterImport = $eventCount;

        Event::dispatch(new ModelsImported(new Collection([new SearchableModel])));

        $this->assertGreaterThan(0, $countAfterImport);
        $this->assertSame($countAfterImport + 1, $eventCount);
    }

    public function testChildFailureAbortsImportDrainsTheRunnerAndDoesNotContaminateLaterImports(): void
    {
        $this->app->make('config')->set('scout.command_concurrency', 1);

        SearchableModel::withoutSyncingToSearch(function (): void {
            for ($i = 1; $i <= 3; ++$i) {
                SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
            }
        });

        $failure = new RuntimeException('Search transport failed.');
        $failingEngine = m::mock(Engine::class);
        $failingEngine->shouldReceive('update')->once()->andThrow($failure);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')->andReturn($failingEngine);
        $this->app->instance(EngineManager::class, $manager);

        $command = $this->app->make(Kernel::class)->getArtisan()->find('scout:import');
        $tester = new CommandTester(clone $command);

        try {
            $tester->execute([
                'model' => SearchableModel::class,
                '--chunk' => 1,
            ]);
            $this->fail('The child import failure was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertStringNotContainsString('records have been imported', $tester->getDisplay());
        $this->assertFalse(CoroutineContext::has(SearchableModel::SCOUT_RUNNER_CONTEXT_KEY));

        $successfulEngine = m::mock(Engine::class);
        $successfulEngine->shouldReceive('update')->times(3);

        $manager = m::mock(EngineManager::class);
        $manager->shouldReceive('engine')->andReturn($successfulEngine);
        $this->app->instance(EngineManager::class, $manager);

        $tester = new CommandTester(clone $command);

        $status = $tester->execute([
            'model' => SearchableModel::class,
            '--chunk' => 1,
        ]);

        $this->assertSame(0, $status, $tester->getDisplay());
        $this->assertStringContainsString('records have been imported', $tester->getDisplay());
        $this->assertFalse(CoroutineContext::has(SearchableModel::SCOUT_RUNNER_CONTEXT_KEY));
    }
}
