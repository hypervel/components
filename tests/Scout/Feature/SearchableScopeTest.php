<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Feature;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Events\ModelsFlushed;
use Hypervel\Scout\Events\ModelsImported;
use Hypervel\Scout\Scout;
use Hypervel\Scout\Searchable;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Scout\Models\ConditionalSearchableModel;
use Hypervel\Tests\Scout\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;

/**
 * Tests for SearchableScope macros and event dispatch.
 */
class SearchableScopeTest extends ScoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Use collection driver to avoid external service calls
        $this->app->make('config')->set('scout.driver', 'collection');
    }

    public function testSearchableMacroDispatchesModelsImportedEvent(): void
    {
        SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Second', 'body' => 'Body']);

        Event::fake([ModelsImported::class]);

        SearchableModel::query()->searchable();

        Event::assertDispatched(ModelsImported::class, function (ModelsImported $event) {
            return $event->models->count() === 2;
        });
    }

    public function testUnsearchableMacroDispatchesModelsFlushedEvent(): void
    {
        SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Second', 'body' => 'Body']);

        Event::fake([ModelsFlushed::class]);

        SearchableModel::query()->unsearchable();

        Event::assertDispatched(ModelsFlushed::class, function (ModelsFlushed $event) {
            return $event->models->count() === 2;
        });
    }

    public function testSearchableMacroFiltersModelsThroughShouldBeSearchable(): void
    {
        // ConditionalSearchableModel has shouldBeSearchable() that returns false
        // when title contains "hidden"
        ConditionalSearchableModel::create(['title' => 'Visible Item', 'body' => 'Body']);
        ConditionalSearchableModel::create(['title' => 'hidden Item', 'body' => 'Body']);
        ConditionalSearchableModel::create(['title' => 'Another Visible', 'body' => 'Body']);

        ConditionalSearchableModel::query()->searchable();

        // Search should only find the 2 visible models (the hidden one was filtered out)
        $searchResults = ConditionalSearchableModel::search('')->get();

        $this->assertCount(2, $searchResults);
        $this->assertTrue($searchResults->contains('title', 'Visible Item'));
        $this->assertTrue($searchResults->contains('title', 'Another Visible'));
        $this->assertFalse($searchResults->contains('title', 'hidden Item'));
    }

    public function testSearchableMacroRespectsCustomChunkSize(): void
    {
        // Create 5 models
        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Item {$i}", 'body' => 'Body']);
        }

        Event::fake([ModelsImported::class]);

        // Use chunk size of 2, should dispatch 3 events (2 + 2 + 1)
        SearchableModel::query()->searchable(2);

        Event::assertDispatched(ModelsImported::class, 3);
    }

    public function testUnsearchableMacroRespectsCustomChunkSize(): void
    {
        // Create 5 models
        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Item {$i}", 'body' => 'Body']);
        }

        Event::fake([ModelsFlushed::class]);

        // Use chunk size of 2, should dispatch 3 events (2 + 2 + 1)
        SearchableModel::query()->unsearchable(2);

        Event::assertDispatched(ModelsFlushed::class, 3);
    }

    public function testSearchableMacrosUseConfiguredChunkSizesByDefault(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            SearchableModel::create(['title' => "Item {$i}", 'body' => 'Body']);
        }

        config([
            'scout.chunk.searchable' => 2,
            'scout.chunk.unsearchable' => 3,
        ]);

        Event::fake([ModelsImported::class, ModelsFlushed::class]);

        SearchableModel::query()->searchable();
        SearchableModel::query()->unsearchable();

        Event::assertDispatched(ModelsImported::class, 3);
        Event::assertDispatched(ModelsFlushed::class, 2);
    }

    public function testSearchableMacroWorksWithQueryConstraints(): void
    {
        SearchableModel::create(['title' => 'Include This', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Exclude This', 'body' => 'Body']);

        Event::fake([ModelsImported::class]);

        // Only make models with "Include" in title searchable
        SearchableModel::query()
            ->where('title', 'like', '%Include%')
            ->searchable();

        Event::assertDispatched(ModelsImported::class, function (ModelsImported $event) {
            return $event->models->count() === 1
                && $event->models->first()->title === 'Include This';
        });
    }

    public function testSearchableMacroReportsProgressWithoutEventListeners(): void
    {
        SearchableModel::create(['title' => 'First', 'body' => 'Body']);
        SearchableModel::create(['title' => 'Second', 'body' => 'Body']);

        $reported = 0;

        Scout::whileReportingImportProgress(
            function (Collection $models) use (&$reported): void {
                $reported += $models->count();
            },
            fn () => SearchableModel::query()->searchable(),
        );

        $this->assertSame(2, $reported);
    }

    public function testHasManyThroughMacrosUseTheRelationChunkQuery(): void
    {
        $owner = ScoutThroughOwner::createQuietly();
        $intermediate = ScoutThroughIntermediate::createQuietly(['owner_id' => $owner->getKey()]);

        $modelIds = [];

        for ($i = 1; $i <= 3; ++$i) {
            $modelIds[] = ScoutThroughSearchableModel::createQuietly([
                'intermediate_id' => $intermediate->getKey(),
                'title' => "Model {$i}",
            ])->getKey();
        }

        config([
            'scout.chunk.searchable' => 2,
            'scout.chunk.unsearchable' => 2,
        ]);

        Event::fake([ModelsImported::class, ModelsFlushed::class]);

        $owner->searchableModels()->searchable();

        Event::assertDispatched(ModelsImported::class, 2);
        $importedIds = Event::dispatched(ModelsImported::class)
            ->flatMap(fn (array $arguments) => $arguments[0]->models->modelKeys())
            ->sort()
            ->values()
            ->all();

        $this->assertSame($modelIds, $importedIds);

        $owner->searchableModels()->unsearchable();

        Event::assertDispatched(ModelsFlushed::class, 2);
        $flushedIds = Event::dispatched(ModelsFlushed::class)
            ->flatMap(fn (array $arguments) => $arguments[0]->models->modelKeys())
            ->sort()
            ->values()
            ->all();

        $this->assertSame($modelIds, $flushedIds);
    }
}

class ScoutThroughOwner extends Model
{
    protected ?string $table = 'scout_through_owners';

    protected array $guarded = [];

    /**
     * Get the searchable models through the intermediate records.
     *
     * @return HasManyThrough<ScoutThroughSearchableModel, ScoutThroughIntermediate, $this>
     */
    public function searchableModels(): HasManyThrough
    {
        return $this->hasManyThrough(
            ScoutThroughSearchableModel::class,
            ScoutThroughIntermediate::class,
            'owner_id',
            'intermediate_id',
        );
    }
}

class ScoutThroughIntermediate extends Model
{
    protected ?string $table = 'scout_through_intermediates';

    protected array $guarded = [];
}

class ScoutThroughSearchableModel extends Model implements SearchableInterface
{
    use Searchable;

    protected ?string $table = 'scout_through_models';

    protected array $guarded = [];
}
