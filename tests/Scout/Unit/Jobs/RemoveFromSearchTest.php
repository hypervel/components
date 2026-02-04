<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Jobs;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Scout\Jobs\RemoveableScoutCollection;
use Hypervel\Scout\Jobs\RemoveFromSearch;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;
use Mockery;

/**
 * Tests for RemoveFromSearch job.
 *
 * @internal
 * @coversNothing
 */
class RemoveFromSearchTest extends ScoutTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testHandleCallsEngineDelete(): void
    {
        $model1 = new SearchableModel(['title' => 'First', 'body' => 'Content']);
        $model1->id = 1;

        $model2 = new SearchableModel(['title' => 'Second', 'body' => 'Content']);
        $model2->id = 2;

        $collection = new Collection([$model1, $model2]);

        $engine = Mockery::mock(\Hypervel\Scout\Engine::class);
        $engine->shouldReceive('delete')
            ->once()
            ->with(Mockery::on(function ($models) {
                return $models instanceof RemoveableScoutCollection
                    && $models->count() === 2;
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

        $job = new RemoveFromSearch($collection);
        $job->handle();
    }

    public function testHandleDoesNothingForEmptyCollection(): void
    {
        $collection = new Collection([]);

        $engine = Mockery::mock(\Hypervel\Scout\Engine::class);
        $engine->shouldNotReceive('delete');

        $this->app->instance(\Hypervel\Scout\EngineManager::class, new class($engine) {
            public function __construct(private $engine)
            {
            }

            public function engine(): \Hypervel\Scout\Engine
            {
                return $this->engine;
            }
        });

        $job = new RemoveFromSearch($collection);
        $job->handle();

        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    public function testConstructorWrapsCollectionInRemoveableScoutCollection(): void
    {
        $model = new SearchableModel(['title' => 'Test', 'body' => 'Content']);
        $model->id = 1;

        $collection = new Collection([$model]);

        $job = new RemoveFromSearch($collection);

        $this->assertInstanceOf(RemoveableScoutCollection::class, $job->models);
        $this->assertCount(1, $job->models);
    }
}
