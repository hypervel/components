<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Typesense;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Tests\Scout\Models\TypesenseSearchableModel;

/**
 * Integration tests for Typesense filtering operations.
 *
 * @internal
 * @coversNothing
 */
class TypesenseFilteringIntegrationTest extends TypesenseScoutIntegrationTestCase
{
    public function testWhereFiltersResultsByExactMatch(): void
    {
        TypesenseSearchableModel::create(['title' => 'PHP Guide', 'body' => 'Learn PHP']);
        TypesenseSearchableModel::create(['title' => 'JavaScript Guide', 'body' => 'Learn JS']);
        TypesenseSearchableModel::create(['title' => 'PHP Advanced', 'body' => 'Advanced PHP']);

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $results = TypesenseSearchableModel::search('')
            ->where('title', 'PHP Guide')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('PHP Guide', $results->first()->title);
    }

    public function testWhereWithNumericIdAsString(): void
    {
        $model1 = TypesenseSearchableModel::create(['title' => 'First', 'body' => 'Body']);
        $model2 = TypesenseSearchableModel::create(['title' => 'Second', 'body' => 'Body']);

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $results = TypesenseSearchableModel::search('')
            ->where('id', (string) $model1->id)
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame($model1->id, $results->first()->id);
    }

    public function testWhereInFiltersResultsByMultipleValues(): void
    {
        $model1 = TypesenseSearchableModel::create(['title' => 'First', 'body' => 'Body']);
        $model2 = TypesenseSearchableModel::create(['title' => 'Second', 'body' => 'Body']);
        $model3 = TypesenseSearchableModel::create(['title' => 'Third', 'body' => 'Body']);

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $results = TypesenseSearchableModel::search('')
            ->whereIn('id', [(string) $model1->id, (string) $model3->id])
            ->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $model1->id));
        $this->assertTrue($results->contains('id', $model3->id));
        $this->assertFalse($results->contains('id', $model2->id));
    }

    public function testWhereNotInExcludesSpecifiedValues(): void
    {
        $model1 = TypesenseSearchableModel::create(['title' => 'First', 'body' => 'Body']);
        $model2 = TypesenseSearchableModel::create(['title' => 'Second', 'body' => 'Body']);
        $model3 = TypesenseSearchableModel::create(['title' => 'Third', 'body' => 'Body']);

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $results = TypesenseSearchableModel::search('')
            ->whereNotIn('id', [(string) $model1->id, (string) $model3->id])
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame($model2->id, $results->first()->id);
    }

    public function testMultipleWhereClausesAreCombinedWithAnd(): void
    {
        TypesenseSearchableModel::create(['title' => 'PHP Guide', 'body' => 'Content A']);
        TypesenseSearchableModel::create(['title' => 'PHP Guide', 'body' => 'Content B']);
        TypesenseSearchableModel::create(['title' => 'JS Guide', 'body' => 'Content A']);

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $results = TypesenseSearchableModel::search('')
            ->where('title', 'PHP Guide')
            ->where('body', 'Content A')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('PHP Guide', $results->first()->title);
        $this->assertSame('Content A', $results->first()->body);
    }

    public function testCombinedWhereAndWhereIn(): void
    {
        $model1 = TypesenseSearchableModel::create(['title' => 'PHP', 'body' => 'A']);
        $model2 = TypesenseSearchableModel::create(['title' => 'PHP', 'body' => 'B']);
        $model3 = TypesenseSearchableModel::create(['title' => 'JS', 'body' => 'A']);
        $model4 = TypesenseSearchableModel::create(['title' => 'PHP', 'body' => 'C']);

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $results = TypesenseSearchableModel::search('')
            ->where('title', 'PHP')
            ->whereIn('body', ['A', 'B'])
            ->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $model1->id));
        $this->assertTrue($results->contains('id', $model2->id));
    }
}
