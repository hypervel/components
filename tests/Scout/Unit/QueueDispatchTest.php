<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Scout\Jobs\MakeSearchable;
use Hypervel\Scout\Jobs\RemoveFromSearch;
use Hypervel\Scout\Scout;
use Hypervel\Support\Facades\Bus;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;

/**
 * Tests for Scout's queue dispatch behavior, including after_commit support.
 *
 * @internal
 * @coversNothing
 */
class QueueDispatchTest extends ScoutTestCase
{
    protected function tearDown(): void
    {
        Scout::resetJobClasses();
        parent::tearDown();
    }

    public function testQueueMakeSearchableDispatchesJobWhenQueueEnabled(): void
    {
        $this->app->make('config')->set('scout.queue.enabled', true);
        $this->app->make('config')->set('scout.queue.after_commit', false);

        Bus::fake([MakeSearchable::class]);

        $model = new SearchableModel(['title' => 'Test', 'body' => 'Content']);
        $model->id = 1;

        $model->queueMakeSearchable(new Collection([$model]));

        Bus::assertDispatched(MakeSearchable::class, function (MakeSearchable $job) {
            // afterCommit should be null or false when after_commit config is disabled
            return $job->afterCommit !== true;
        });
    }

    public function testQueueMakeSearchableDispatchesWithAfterCommitWhenEnabled(): void
    {
        $this->app->make('config')->set('scout.queue.enabled', true);
        $this->app->make('config')->set('scout.queue.after_commit', true);

        Bus::fake([MakeSearchable::class]);

        $model = new SearchableModel(['title' => 'Test', 'body' => 'Content']);
        $model->id = 1;

        $model->queueMakeSearchable(new Collection([$model]));

        Bus::assertDispatched(MakeSearchable::class, function (MakeSearchable $job) {
            return $job->afterCommit === true;
        });
    }

    public function testQueueRemoveFromSearchDispatchesJobWhenQueueEnabled(): void
    {
        $this->app->make('config')->set('scout.queue.enabled', true);
        $this->app->make('config')->set('scout.queue.after_commit', false);

        Bus::fake([RemoveFromSearch::class]);

        $model = new SearchableModel(['title' => 'Test', 'body' => 'Content']);
        $model->id = 1;

        $model->queueRemoveFromSearch(new Collection([$model]));

        Bus::assertDispatched(RemoveFromSearch::class, function (RemoveFromSearch $job) {
            // afterCommit should be null or false when after_commit config is disabled
            return $job->afterCommit !== true;
        });
    }

    public function testQueueRemoveFromSearchDispatchesWithAfterCommitWhenEnabled(): void
    {
        $this->app->make('config')->set('scout.queue.enabled', true);
        $this->app->make('config')->set('scout.queue.after_commit', true);

        Bus::fake([RemoveFromSearch::class]);

        $model = new SearchableModel(['title' => 'Test', 'body' => 'Content']);
        $model->id = 1;

        $model->queueRemoveFromSearch(new Collection([$model]));

        Bus::assertDispatched(RemoveFromSearch::class, function (RemoveFromSearch $job) {
            return $job->afterCommit === true;
        });
    }

    public function testQueueMakeSearchableDoesNotDispatchJobWhenQueueDisabled(): void
    {
        $this->app->make('config')->set('scout.queue.enabled', false);

        Bus::fake([MakeSearchable::class]);

        $model = new SearchableModel(['title' => 'Test', 'body' => 'Content']);
        $model->id = 1;

        $model->queueMakeSearchable(new Collection([$model]));

        // When queue is disabled, the job should not be dispatched via Bus
        // Instead, it uses Coroutine::defer() or direct execution
        Bus::assertNotDispatched(MakeSearchable::class);
    }

    public function testQueueRemoveFromSearchDoesNotDispatchJobWhenQueueDisabled(): void
    {
        $this->app->make('config')->set('scout.queue.enabled', false);

        Bus::fake([RemoveFromSearch::class]);

        $model = new SearchableModel(['title' => 'Test', 'body' => 'Content']);
        $model->id = 1;

        $model->queueRemoveFromSearch(new Collection([$model]));

        // When queue is disabled, the job should not be dispatched via Bus
        Bus::assertNotDispatched(RemoveFromSearch::class);
    }

    public function testEmptyCollectionDoesNotDispatchMakeSearchableJob(): void
    {
        $this->app->make('config')->set('scout.queue.enabled', true);

        Bus::fake([MakeSearchable::class]);

        $model = new SearchableModel();
        $model->queueMakeSearchable(new Collection([]));

        Bus::assertNotDispatched(MakeSearchable::class);
    }

    public function testEmptyCollectionDoesNotDispatchRemoveFromSearchJob(): void
    {
        $this->app->make('config')->set('scout.queue.enabled', true);

        Bus::fake([RemoveFromSearch::class]);

        $model = new SearchableModel();
        $model->queueRemoveFromSearch(new Collection([]));

        Bus::assertNotDispatched(RemoveFromSearch::class);
    }

    public function testQueueMakeSearchableDispatchesCustomJobClass(): void
    {
        $this->app->make('config')->set('scout.queue.enabled', true);

        Scout::makeSearchableUsing(TestCustomMakeSearchable::class);

        Bus::fake([TestCustomMakeSearchable::class]);

        $model = new SearchableModel(['title' => 'Test', 'body' => 'Content']);
        $model->id = 1;

        $model->queueMakeSearchable(new Collection([$model]));

        Bus::assertDispatched(TestCustomMakeSearchable::class);
    }

    public function testQueueRemoveFromSearchDispatchesCustomJobClass(): void
    {
        $this->app->make('config')->set('scout.queue.enabled', true);

        Scout::removeFromSearchUsing(TestCustomRemoveFromSearch::class);

        Bus::fake([TestCustomRemoveFromSearch::class]);

        $model = new SearchableModel(['title' => 'Test', 'body' => 'Content']);
        $model->id = 1;

        $model->queueRemoveFromSearch(new Collection([$model]));

        Bus::assertDispatched(TestCustomRemoveFromSearch::class);
    }
}

/**
 * Custom job class for testing custom MakeSearchable dispatch.
 */
class TestCustomMakeSearchable extends MakeSearchable
{
}

/**
 * Custom job class for testing custom RemoveFromSearch dispatch.
 */
class TestCustomRemoveFromSearch extends RemoveFromSearch
{
}
