<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Jobs;

use Hypervel\Contracts\Database\ModelIdentifier;
use Hypervel\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Jobs\RemoveableScoutCollection;
use Hypervel\Scout\Jobs\RemoveFromSearch;
use Hypervel\Scout\Jobs\RemoveFromSearchUniquely;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Scout\Models\CustomScoutKeyModel;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;
use Mockery as m;

/**
 * Tests for RemoveFromSearch job.
 */
class RemoveFromSearchTest extends ScoutTestCase
{
    public function testHandleCallsEngineDelete(): void
    {
        $model1 = new SearchableModel(['title' => 'First', 'body' => 'Content']);
        $model1->id = 1;

        $model2 = new SearchableModel(['title' => 'Second', 'body' => 'Content']);
        $model2->id = 2;

        $collection = new Collection([$model1, $model2]);

        $engine = m::mock(Engine::class);
        $engine->shouldReceive('delete')
            ->once()
            ->with(m::on(function ($models) {
                return $models instanceof RemoveableScoutCollection
                    && $models->count() === 2;
            }));

        $this->app->instance(EngineManager::class, new class($engine) {
            public function __construct(private Engine $engine)
            {
            }

            public function engine(): Engine
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

        $engine = m::mock(Engine::class);
        $engine->shouldNotReceive('delete');

        $this->app->instance(EngineManager::class, new class($engine) {
            public function __construct(private Engine $engine)
            {
            }

            public function engine(): Engine
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

    public function testModelsAreRestoredWithoutQueryingTheDatabase(): void
    {
        $model = SearchableModel::create(['title' => 'Removed', 'body' => 'Content']);
        $job = new RemoveFromSearch(Collection::make([$model]));

        SearchableModel::query()->whereKey($model->getKey())->delete();

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        /** @var RemoveFromSearch $restored */
        $restored = unserialize(serialize($job));

        $this->assertSame([], $connection->getQueryLog());
        $this->assertInstanceOf(SearchableModel::class, $restored->models->first());
        $this->assertSame($model->getScoutKey(), $restored->models->first()->getAttribute('id'));
    }

    public function testCustomScoutKeyAndConnectionAreRestoredExactly(): void
    {
        $model = (new CustomScoutKeyModel)->setConnection('archive');
        $model->id = 42;

        /** @var RemoveFromSearch $restored */
        $restored = unserialize(serialize(new RemoveFromSearch(Collection::make([$model]))));
        $restoredModel = $restored->models->first();

        $this->assertInstanceOf(CustomScoutKeyModel::class, $restoredModel);
        $this->assertSame('archive', $restoredModel->getConnectionName());
        $this->assertSame('string', $restoredModel->getKeyType());
        $this->assertSame('custom-key.42', $restoredModel->getAttribute('id'));
    }

    public function testMorphAliasesAreResolvedWhileRestoringModels(): void
    {
        Relation::morphMap(['searchable' => SearchableModel::class]);
        ModelIdentifier::useMorphMap();

        $model = new SearchableModel;
        $model->id = 1;

        /** @var RemoveFromSearch $restored */
        $restored = unserialize(serialize(new RemoveFromSearch(Collection::make([$model]))));

        $this->assertInstanceOf(SearchableModel::class, $restored->models->first());
        $this->assertSame(1, $restored->models->first()->getAttribute('id'));
    }

    public function testJobPropertiesAreSetFromConfig(): void
    {
        config()->set('scout.jobs', [
            'tries' => 3,
            'backoff' => [1, 5, 10],
            'max_exceptions' => 2,
        ]);

        $job = new RemoveFromSearch(Collection::make([$this->model(1)]));

        $this->assertSame(3, $job->tries);
        $this->assertSame([1, 5, 10], $job->backoff);
        $this->assertSame(2, $job->maxExceptions);
        $this->assertTrue($job->failOnTimeout);
    }

    public function testSubclassJobPropertiesAreNotOverriddenByConfig(): void
    {
        config()->set('scout.jobs', [
            'tries' => 1,
            'backoff' => [1, 5, 10],
            'max_exceptions' => 1,
        ]);

        $job = new OverriddenRemoveFromSearch(Collection::make([$this->model(1)]));

        $this->assertSame(5, $job->tries);
        $this->assertSame([2, 4, 8, 16, 32], $job->backoff());
        $this->assertSame(3, $job->maxExceptions);
        $this->assertFalse($job->failOnTimeout);
    }

    public function testUniqueIdUsesSortedScoutKeys(): void
    {
        $models = Collection::make([$this->model(2), $this->model(1)]);

        $job = new RemoveFromSearchUniquely($models);

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame(3600, $job->uniqueFor);
        $this->assertSame(
            (new RemoveFromSearchUniquely($models->reverse()->values()))->uniqueId(),
            $job->uniqueId()
        );
    }

    private function model(int $id): SearchableModel
    {
        return (new SearchableModel)->setAttribute('id', $id);
    }
}

class OverriddenRemoveFromSearch extends RemoveFromSearch
{
    public $tries = 5;

    public $maxExceptions = 3;

    public $failOnTimeout = false;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [2, 4, 8, 16, 32];
    }
}
