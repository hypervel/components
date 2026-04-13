<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Scout\Jobs\MakeSearchable;
use Hypervel\Tests\Scout\Models\FilteringSearchableModel;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;
use Mockery as m;

/**
 * Tests for makeSearchableUsing() behavior.
 *
 * @internal
 * @coversNothing
 */
class MakeSearchableUsingTest extends ScoutTestCase
{
    public function testSyncMakeSearchablePassesFilteredCollectionToEngine(): void
    {
        // Create models - one published, one draft
        $published = new FilteringSearchableModel(['title' => 'Published Post', 'body' => 'Content']);
        $published->id = 1;

        $draft = new FilteringSearchableModel(['title' => 'Draft: Work in Progress', 'body' => 'Content']);
        $draft->id = 2;

        $collection = new Collection([$published, $draft]);

        // Mock the engine to verify what gets passed to update()
        $engine = m::mock(\Hypervel\Scout\Engine::class);
        $engine->shouldReceive('update')
            ->once()
            ->with(m::on(function ($models) {
                // Should only contain the published model, not the draft
                return $models->count() === 1
                    && $models->first()->id === 1
                    && $models->first()->title === 'Published Post';
            }));

        // Replace the engine
        $this->app->instance(\Hypervel\Scout\EngineManager::class, new class($engine) {
            public function __construct(private $engine)
            {
            }

            public function engine(): \Hypervel\Scout\Engine
            {
                return $this->engine;
            }
        });

        $published->syncMakeSearchable($collection);
    }

    public function testSyncMakeSearchableHandlesEmptyFilteredCollection(): void
    {
        // Create only draft models that will be filtered out
        $draft1 = new FilteringSearchableModel(['title' => 'Draft: First', 'body' => 'Content']);
        $draft1->id = 1;

        $draft2 = new FilteringSearchableModel(['title' => 'Draft: Second', 'body' => 'Content']);
        $draft2->id = 2;

        $collection = new Collection([$draft1, $draft2]);

        // Mock the engine - update should NOT be called
        $engine = m::mock(\Hypervel\Scout\Engine::class);
        $engine->shouldNotReceive('update');

        $this->app->instance(\Hypervel\Scout\EngineManager::class, new class($engine) {
            public function __construct(private $engine)
            {
            }

            public function engine(): \Hypervel\Scout\Engine
            {
                return $this->engine;
            }
        });

        // This should not throw, even though all models are filtered out
        $draft1->syncMakeSearchable($collection);

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    public function testMakeSearchableJobPassesFilteredCollectionToEngine(): void
    {
        // Create models - one published, one draft
        $published = new FilteringSearchableModel(['title' => 'Published Post', 'body' => 'Content']);
        $published->id = 1;

        $draft = new FilteringSearchableModel(['title' => 'Draft: Work in Progress', 'body' => 'Content']);
        $draft->id = 2;

        $collection = new Collection([$published, $draft]);

        // Mock the engine to verify what gets passed to update()
        $engine = m::mock(\Hypervel\Scout\Engine::class);
        $engine->shouldReceive('update')
            ->once()
            ->with(m::on(function ($models) {
                // Should only contain the published model, not the draft
                return $models->count() === 1
                    && $models->first()->id === 1
                    && $models->first()->title === 'Published Post';
            }));

        $this->app->instance(\Hypervel\Scout\EngineManager::class, new class($engine) {
            public function __construct(private $engine)
            {
            }

            public function engine(): \Hypervel\Scout\Engine
            {
                return $this->engine;
            }
        });

        $job = new MakeSearchable($collection);
        $job->handle();
    }

    public function testMakeSearchableJobHandlesEmptyFilteredCollection(): void
    {
        // Create only draft models that will be filtered out
        $draft1 = new FilteringSearchableModel(['title' => 'Draft: First', 'body' => 'Content']);
        $draft1->id = 1;

        $draft2 = new FilteringSearchableModel(['title' => 'Draft: Second', 'body' => 'Content']);
        $draft2->id = 2;

        $collection = new Collection([$draft1, $draft2]);

        // Mock the engine - update should NOT be called
        $engine = m::mock(\Hypervel\Scout\Engine::class);
        $engine->shouldNotReceive('update');

        $this->app->instance(\Hypervel\Scout\EngineManager::class, new class($engine) {
            public function __construct(private $engine)
            {
            }

            public function engine(): \Hypervel\Scout\Engine
            {
                return $this->engine;
            }
        });

        $job = new MakeSearchable($collection);

        // This should not throw, even though all models are filtered out
        $job->handle();

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    public function testMakeSearchableUsingDefaultBehaviorPassesThroughUnchanged(): void
    {
        // Using the regular SearchableModel which doesn't override makeSearchableUsing
        $model1 = new SearchableModel(['title' => 'First', 'body' => 'Content']);
        $model1->id = 1;

        $model2 = new SearchableModel(['title' => 'Second', 'body' => 'Content']);
        $model2->id = 2;

        $collection = new Collection([$model1, $model2]);

        // Mock the engine to verify all models are passed
        $engine = m::mock(\Hypervel\Scout\Engine::class);
        $engine->shouldReceive('update')
            ->once()
            ->with(m::on(function ($models) {
                return $models->count() === 2;
            }));

        $this->app->instance(\Hypervel\Scout\EngineManager::class, new class($engine) {
            public function __construct(private $engine)
            {
            }

            public function engine(): \Hypervel\Scout\Engine
            {
                return $this->engine;
            }
        });

        $model1->syncMakeSearchable($collection);
    }
}
