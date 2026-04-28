<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Scout\Events\ModelsFlushed;
use Hypervel\Scout\Events\ModelsImported;
use Hypervel\Scout\Scout;
use Hypervel\Support\ClassInvoker;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;

/**
 * Tests for Scout configuration options.
 */
class ConfigTest extends ScoutTestCase
{
    public function testCommandConcurrencyConfigIsUsed(): void
    {
        $this->app->make('config')->set('scout.command_concurrency', 25);

        $model = new SearchableModel(['title' => 'Test', 'body' => 'Content']);
        $model->id = 1;
        $model->exists = true;

        Scout::whileImporting(function () use ($model): void {
            $model->queueMakeSearchable(new Collection([$model]));
        });

        $scoutRunner = CoroutineContext::get(SearchableModel::SCOUT_RUNNER_CONTEXT_KEY);

        $this->assertInstanceOf(WaitConcurrent::class, $scoutRunner);
        $this->assertSame(25, (new ClassInvoker($scoutRunner))->limit);
    }

    public function testChunkSearchableConfigAffectsImportEvents(): void
    {
        $this->app->make('config')->set('scout.chunk.searchable', 2);

        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Model {$i}", 'body' => 'Content']);
        }

        $eventCount = 0;
        Event::listen(ModelsImported::class, function () use (&$eventCount): void {
            ++$eventCount;
        });

        SearchableModel::makeAllSearchable();
        SearchableModel::waitForSearchableJobs();

        // 5 models / chunk size 2 → 3 events (2+2+1)
        $this->assertSame(3, $eventCount);
    }

    public function testChunkUnsearchableConfigAffectsFlushEvents(): void
    {
        $this->app->make('config')->set('scout.chunk.unsearchable', 2);

        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Model {$i}", 'body' => 'Content']);
        }

        $eventCount = 0;
        Event::listen(ModelsFlushed::class, function () use (&$eventCount): void {
            ++$eventCount;
        });

        SearchableModel::query()->unsearchable();
        SearchableModel::waitForSearchableJobs();

        // 5 models / chunk size 2 → 3 events (2+2+1)
        $this->assertSame(3, $eventCount);
    }
}
