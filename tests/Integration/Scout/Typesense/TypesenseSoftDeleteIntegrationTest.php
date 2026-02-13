<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Typesense;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Tests\Scout\Models\TypesenseSoftDeleteSearchableModel;

/**
 * Integration tests for Scout soft delete behavior with Typesense.
 *
 * @internal
 * @coversNothing
 */
class TypesenseSoftDeleteIntegrationTest extends TypesenseScoutIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Enable soft delete support in Scout
        $this->app->make('config')->set('scout.soft_delete', true);
    }

    public function testDefaultSearchExcludesSoftDeletedModels(): void
    {
        $model1 = TypesenseSoftDeleteSearchableModel::create(['title' => 'Active One', 'body' => 'Content']);
        $model2 = TypesenseSoftDeleteSearchableModel::create(['title' => 'Active Two', 'body' => 'Content']);
        $model3 = TypesenseSoftDeleteSearchableModel::create(['title' => 'Deleted One', 'body' => 'Content']);

        // Index all models
        TypesenseSoftDeleteSearchableModel::withTrashed()->get()->each(
            fn ($m) => $this->engine->update(new EloquentCollection([$m]))
        );

        // Soft delete one model and re-index it
        $model3->delete();
        $this->engine->update(new EloquentCollection([$model3->fresh()]));

        // Default search should exclude soft-deleted model
        $results = TypesenseSoftDeleteSearchableModel::search('')->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $model1->id));
        $this->assertTrue($results->contains('id', $model2->id));
        $this->assertFalse($results->contains('id', $model3->id));
    }

    public function testWithTrashedIncludesSoftDeletedModels(): void
    {
        $model1 = TypesenseSoftDeleteSearchableModel::create(['title' => 'Active', 'body' => 'Content']);
        $model2 = TypesenseSoftDeleteSearchableModel::create(['title' => 'Deleted', 'body' => 'Content']);

        // Index all models
        TypesenseSoftDeleteSearchableModel::withTrashed()->get()->each(
            fn ($m) => $this->engine->update(new EloquentCollection([$m]))
        );

        // Soft delete one model and re-index it
        $model2->delete();
        $this->engine->update(new EloquentCollection([$model2->fresh()]));

        // withTrashed should include soft-deleted model
        $results = TypesenseSoftDeleteSearchableModel::search('')->withTrashed()->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $model1->id));
        $this->assertTrue($results->contains('id', $model2->id));
    }

    public function testOnlyTrashedReturnsOnlySoftDeletedModels(): void
    {
        $model1 = TypesenseSoftDeleteSearchableModel::create(['title' => 'Active', 'body' => 'Content']);
        $model2 = TypesenseSoftDeleteSearchableModel::create(['title' => 'Deleted One', 'body' => 'Content']);
        $model3 = TypesenseSoftDeleteSearchableModel::create(['title' => 'Deleted Two', 'body' => 'Content']);

        // Index all models
        TypesenseSoftDeleteSearchableModel::withTrashed()->get()->each(
            fn ($m) => $this->engine->update(new EloquentCollection([$m]))
        );

        // Soft delete two models and re-index them
        $model2->delete();
        $model3->delete();
        $this->engine->update(new EloquentCollection([$model2->fresh()]));
        $this->engine->update(new EloquentCollection([$model3->fresh()]));

        // onlyTrashed should return only soft-deleted models
        $results = TypesenseSoftDeleteSearchableModel::search('')->onlyTrashed()->get();

        $this->assertCount(2, $results);
        $this->assertFalse($results->contains('id', $model1->id));
        $this->assertTrue($results->contains('id', $model2->id));
        $this->assertTrue($results->contains('id', $model3->id));
    }
}
