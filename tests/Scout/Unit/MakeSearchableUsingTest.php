<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Jobs\MakeSearchable;
use Hypervel\Tests\Scout\Fixtures\Models\FilteringSearchableModel;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

/**
 * Tests for makeSearchableUsing() behavior.
 */
class MakeSearchableUsingTest extends ScoutTestCase
{
    use VerifiesDoubles;

    public function testSyncMakeSearchablePassesFilteredCollectionToEngine(): void
    {
        // Create models - one published, one draft
        $published = new FilteringSearchableModel(['title' => 'Published Post', 'body' => 'Content']);
        $published->id = 1;

        $draft = new FilteringSearchableModel(['title' => 'Draft: Work in Progress', 'body' => 'Content']);
        $draft->id = 2;

        $collection = new Collection([$published, $draft]);

        // Mock the engine to verify what gets passed to update()
        $engine = Double::for(Engine::class)->strict();
        $engine->expects('runUpdate')
            ->with(Argument::satisfies(function ($models) {
                // Should only contain the published model, not the draft
                return $models->count() === 1
                    && $models->first()->id === 1
                    && $models->first()->title === 'Published Post';
            }));

        // Replace the engine
        $manager = Double::for(EngineManager::class)->strict();
        $manager->allows('engine')->returns($engine);
        $this->app->instance(EngineManager::class, $manager);

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
        $engine = Double::for(Engine::class)->strict();
        $engine->expects('runUpdate')->never();

        $manager = Double::for(EngineManager::class)->strict();
        $manager->allows('engine')->returns($engine);
        $this->app->instance(EngineManager::class, $manager);

        // This should not throw, even though all models are filtered out
        $draft1->syncMakeSearchable($collection);
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
        $engine = Double::for(Engine::class)->strict();
        $engine->expects('runUpdate')
            ->with(Argument::satisfies(function ($models) {
                // Should only contain the published model, not the draft
                return $models->count() === 1
                    && $models->first()->id === 1
                    && $models->first()->title === 'Published Post';
            }));

        $manager = Double::for(EngineManager::class)->strict();
        $manager->allows('engine')->returns($engine);
        $this->app->instance(EngineManager::class, $manager);

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
        $engine = Double::for(Engine::class)->strict();
        $engine->expects('runUpdate')->never();

        $manager = Double::for(EngineManager::class)->strict();
        $manager->allows('engine')->returns($engine);
        $this->app->instance(EngineManager::class, $manager);

        $job = new MakeSearchable($collection);

        // This should not throw, even though all models are filtered out
        $job->handle();
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
        $engine = Double::for(Engine::class)->strict();
        $engine->expects('runUpdate')
            ->with(Argument::satisfies(function ($models) {
                return $models->count() === 2;
            }));

        $manager = Double::for(EngineManager::class)->strict();
        $manager->allows('engine')->returns($engine);
        $this->app->instance(EngineManager::class, $manager);

        $model1->syncMakeSearchable($collection);
    }
}
