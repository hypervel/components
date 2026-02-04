<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Typesense;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Tests\Scout\Models\TypesenseSearchableModel;

/**
 * Integration tests for Typesense sorting operations.
 *
 * @internal
 * @coversNothing
 */
class TypesenseSortingIntegrationTest extends TypesenseScoutIntegrationTestCase
{
    public function testOrderByAscendingSortsResultsCorrectly(): void
    {
        TypesenseSearchableModel::create(['title' => 'Charlie', 'body' => 'Body']);
        TypesenseSearchableModel::create(['title' => 'Alpha', 'body' => 'Body']);
        TypesenseSearchableModel::create(['title' => 'Bravo', 'body' => 'Body']);

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $results = TypesenseSearchableModel::search('')
            ->orderBy('title', 'asc')
            ->get();

        $this->assertCount(3, $results);
        $this->assertSame('Alpha', $results[0]->title);
        $this->assertSame('Bravo', $results[1]->title);
        $this->assertSame('Charlie', $results[2]->title);
    }

    public function testOrderByDescendingSortsResultsCorrectly(): void
    {
        TypesenseSearchableModel::create(['title' => 'Alpha', 'body' => 'Body']);
        TypesenseSearchableModel::create(['title' => 'Bravo', 'body' => 'Body']);
        TypesenseSearchableModel::create(['title' => 'Charlie', 'body' => 'Body']);

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $results = TypesenseSearchableModel::search('')
            ->orderBy('title', 'desc')
            ->get();

        $this->assertCount(3, $results);
        $this->assertSame('Charlie', $results[0]->title);
        $this->assertSame('Bravo', $results[1]->title);
        $this->assertSame('Alpha', $results[2]->title);
    }

    public function testOrderByDescHelperMethod(): void
    {
        TypesenseSearchableModel::create(['title' => 'Alpha', 'body' => 'Body']);
        TypesenseSearchableModel::create(['title' => 'Bravo', 'body' => 'Body']);

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $results = TypesenseSearchableModel::search('')
            ->orderByDesc('title')
            ->get();

        $this->assertCount(2, $results);
        $this->assertSame('Bravo', $results[0]->title);
        $this->assertSame('Alpha', $results[1]->title);
    }

    public function testMultipleSortFields(): void
    {
        TypesenseSearchableModel::create(['title' => 'Alpha', 'body' => 'Content']);
        TypesenseSearchableModel::create(['title' => 'Alpha', 'body' => 'Content']);
        TypesenseSearchableModel::create(['title' => 'Bravo', 'body' => 'Content']);

        TypesenseSearchableModel::query()->get()->each(fn ($m) => $this->engine->update(new EloquentCollection([$m])));

        $results = TypesenseSearchableModel::search('')
            ->orderBy('title', 'asc')
            ->get();

        $this->assertCount(3, $results);
        // First two should be Alpha (order between them is undefined)
        $this->assertSame('Alpha', $results[0]->title);
        $this->assertSame('Alpha', $results[1]->title);
        $this->assertSame('Bravo', $results[2]->title);
    }
}
