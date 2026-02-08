<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Coroutine\WaitConcurrent;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Scout\Events\ModelsFlushed;
use Hypervel\Scout\Events\ModelsImported;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;
use ReflectionClass;

/**
 * Tests for Scout configuration options.
 *
 * @internal
 * @coversNothing
 */
class ConfigTest extends ScoutTestCase
{
    protected function tearDown(): void
    {
        // Reset the static scout runner between tests
        $this->resetScoutRunner();

        parent::tearDown();
    }

    public function testCommandConcurrencyConfigIsUsed(): void
    {
        // Reset scout runner first to ensure clean state
        // (may have been set by previous tests in the same process)
        $this->resetScoutRunner();

        // Set a specific concurrency value
        $this->app->get('config')->set('scout.command_concurrency', 25);

        // Define SCOUT_COMMAND to trigger the command path
        if (! defined('SCOUT_COMMAND')) {
            define('SCOUT_COMMAND', true);
        }

        // Create a model and trigger searchable job dispatch
        $model = new SearchableModel(['title' => 'Test', 'body' => 'Content']);
        $model->id = 1;
        $model->exists = true;
        $model->queueMakeSearchable(new Collection([$model]));

        // Use reflection to get the static $scoutRunner property
        $reflection = new ReflectionClass(SearchableModel::class);
        $property = $reflection->getProperty('scoutRunner');
        $property->setAccessible(true);
        $scoutRunner = $property->getValue();

        $this->assertInstanceOf(WaitConcurrent::class, $scoutRunner);

        // Get the limit from WaitConcurrent
        $runnerReflection = new ReflectionClass($scoutRunner);
        $limitProperty = $runnerReflection->getProperty('limit');
        $limitProperty->setAccessible(true);
        $limit = $limitProperty->getValue($scoutRunner);

        $this->assertSame(25, $limit);
    }

    public function testChunkSearchableConfigAffectsImportEvents(): void
    {
        // Set a small chunk size to verify multiple events are fired
        $this->app->get('config')->set('scout.chunk.searchable', 2);

        // Create 5 models
        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Model {$i}", 'body' => 'Content']);
        }

        $eventCount = 0;
        Event::listen(ModelsImported::class, function () use (&$eventCount): void {
            ++$eventCount;
        });

        // Call makeAllSearchable which uses chunk config
        SearchableModel::makeAllSearchable();

        // Wait for jobs to complete
        SearchableModel::waitForSearchableJobs();

        // With 5 models and chunk size of 2, we expect 3 events (2+2+1)
        $this->assertSame(3, $eventCount);
    }

    public function testChunkUnsearchableConfigAffectsFlushEvents(): void
    {
        // Set a small chunk size
        $this->app->get('config')->set('scout.chunk.unsearchable', 2);

        // Create 5 models
        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Model {$i}", 'body' => 'Content']);
        }

        $eventCount = 0;
        Event::listen(ModelsFlushed::class, function () use (&$eventCount): void {
            ++$eventCount;
        });

        // Call unsearchable() macro on query which uses chunk config
        SearchableModel::query()->unsearchable();

        // Wait for jobs to complete
        SearchableModel::waitForSearchableJobs();

        // With 5 models and chunk size of 2, we expect 3 events (2+2+1)
        $this->assertSame(3, $eventCount);
    }

    /**
     * Reset the static scout runner property.
     */
    private function resetScoutRunner(): void
    {
        $reflection = new ReflectionClass(SearchableModel::class);
        $property = $reflection->getProperty('scoutRunner');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
