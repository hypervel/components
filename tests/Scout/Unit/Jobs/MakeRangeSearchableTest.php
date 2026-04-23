<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Jobs;

use Hypervel\Scout\Jobs\MakeRangeSearchable;
use Hypervel\Scout\Jobs\MakeSearchable;
use Hypervel\Scout\Scout;
use Hypervel\Support\Facades\Bus;
use Hypervel\Tests\Scout\Models\ConditionalSearchableModel;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\Models\UuidSearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;

/**
 * Tests for MakeRangeSearchable job.
 */
class MakeRangeSearchableTest extends ScoutTestCase
{
    public function testHandleDispatchesMakeSearchableForModelsInRange(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeSearchable::class]);

        (new MakeRangeSearchable(SearchableModel::class, 2, 4))->handle();

        Bus::assertDispatched(MakeSearchable::class, function (MakeSearchable $job) {
            return $job->models->count() === 3
                && $job->models->pluck('id')->all() === [2, 3, 4];
        });
    }

    public function testHandleDispatchesMakeSearchableForSingleIdRange(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeSearchable::class]);

        (new MakeRangeSearchable(SearchableModel::class, 2, 2))->handle();

        Bus::assertDispatched(MakeSearchable::class, function (MakeSearchable $job) {
            return $job->models->count() === 1
                && $job->models->pluck('id')->all() === [2];
        });
    }

    public function testHandleDoesNotDispatchWhenNoModelsInRange(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeSearchable::class]);

        (new MakeRangeSearchable(SearchableModel::class, 10, 15))->handle();

        Bus::assertNotDispatched(MakeSearchable::class);
    }

    public function testHandleHandlesGapsInIds(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        SearchableModel::whereIn('id', [2, 4])->delete();

        Bus::fake([MakeSearchable::class]);

        (new MakeRangeSearchable(SearchableModel::class, 1, 5))->handle();

        Bus::assertDispatched(MakeSearchable::class, function (MakeSearchable $job) {
            return $job->models->count() === 3
                && $job->models->pluck('id')->all() === [1, 3, 5];
        });
    }

    public function testHandleDoesNotDispatchWhenStartGreaterThanEnd(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            SearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        Bus::fake([MakeSearchable::class]);

        (new MakeRangeSearchable(SearchableModel::class, 5, 2))->handle();

        Bus::assertNotDispatched(MakeSearchable::class);
    }

    public function testHandleSkipsModelsThatShouldNotBeSearchable(): void
    {
        ConditionalSearchableModel::create(['title' => 'Visible 1', 'body' => 'Body']);
        ConditionalSearchableModel::create(['title' => 'hidden 2', 'body' => 'Body']);
        ConditionalSearchableModel::create(['title' => 'Visible 3', 'body' => 'Body']);
        ConditionalSearchableModel::create(['title' => 'hidden 4', 'body' => 'Body']);
        ConditionalSearchableModel::create(['title' => 'Visible 5', 'body' => 'Body']);

        Bus::fake([MakeSearchable::class]);

        (new MakeRangeSearchable(ConditionalSearchableModel::class, 1, 5))->handle();

        Bus::assertDispatched(MakeSearchable::class, function (MakeSearchable $job) {
            return $job->models->count() === 3
                && $job->models->pluck('id')->all() === [1, 3, 5];
        });
    }

    public function testHandleDispatchesOnModelsConnectionAndQueue(): void
    {
        $this->app->make('config')->set('scout.queue.connection', 'redis');
        $this->app->make('config')->set('scout.queue.queue', 'scout');

        SearchableModel::create(['title' => 'Test', 'body' => 'Body']);

        Bus::fake([MakeSearchable::class]);

        (new MakeRangeSearchable(SearchableModel::class, 1, 1))->handle();

        Bus::assertDispatched(MakeSearchable::class, function (MakeSearchable $job) {
            return $job->connection === 'redis' && $job->queue === 'scout';
        });
    }

    public function testHandlePropagatesOwnQueueAndConnectionToDispatchedJob(): void
    {
        // Model defaults are set, but the parent job's own queue/connection
        // should win (so --queue=imports on scout:queue-import flows all the
        // way through to the actual indexing job).
        $this->app->make('config')->set('scout.queue.connection', 'redis');
        $this->app->make('config')->set('scout.queue.queue', 'default-scout');

        SearchableModel::create(['title' => 'Test', 'body' => 'Body']);

        Bus::fake([MakeSearchable::class]);

        $job = new MakeRangeSearchable(SearchableModel::class, 1, 1);
        $job->onQueue('imports')->onConnection('sqs');
        $job->handle();

        Bus::assertDispatched(MakeSearchable::class, function (MakeSearchable $dispatched) {
            return $dispatched->queue === 'imports' && $dispatched->connection === 'sqs';
        });
    }

    public function testHandleDispatchesCustomMakeSearchableJobClass(): void
    {
        Scout::makeSearchableUsing(CustomMakeRangeSearchableTestJob::class);

        SearchableModel::create(['title' => 'Test', 'body' => 'Body']);

        Bus::fake([CustomMakeRangeSearchableTestJob::class]);

        (new MakeRangeSearchable(SearchableModel::class, 1, 1))->handle();

        Bus::assertDispatched(CustomMakeRangeSearchableTestJob::class);
    }

    public function testHandleProcessesStringKeyedModelsViaWhereBetween(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            UuidSearchableModel::create(['title' => "Title {$i}", 'body' => 'Body']);
        }

        $rows = UuidSearchableModel::orderBy('id')->get();
        $start = $rows[1]->id;
        $end = $rows[3]->id;

        Bus::fake([MakeSearchable::class]);

        (new MakeRangeSearchable(UuidSearchableModel::class, $start, $end))->handle();

        Bus::assertDispatched(MakeSearchable::class, function (MakeSearchable $job) use ($start, $end) {
            if ($job->models->count() !== 3) {
                return false;
            }
            foreach ($job->models as $model) {
                if ($model->id < $start || $model->id > $end) {
                    return false;
                }
            }
            return true;
        });
    }
}

/**
 * Custom MakeSearchable job for testing Scout::makeSearchableUsing customization.
 */
class CustomMakeRangeSearchableTestJob extends MakeSearchable
{
}
