<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Hypervel\Scout\Exceptions\ScoutException;
use Hypervel\Scout\Jobs\MakeSearchable;
use Hypervel\Scout\Jobs\RemoveFromSearch;
use Hypervel\Support\Facades\Bus;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;

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

        for ($i = 1; $i <= 3; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeSearchable::class, RemoveFromSearch::class]);

        $this->artisan('scout:import', ['model' => SearchableModel::class, '--fresh' => true])
            ->expectsOutputToContain('have been imported')
            ->assertSuccessful();

        Bus::assertNotDispatched(MakeSearchable::class);
        Bus::assertNotDispatched(RemoveFromSearch::class);
    }
}
