<?php

namespace HigherOrderCollectionProxies;

use App\Importer;
use App\User;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;

use function PHPStan\Testing\assertType;

class Score
{
    public int $points = 0;

    public float $ratio = 0.0;

    public function points(): int
    {
        return $this->points;
    }

    public function ratio(): float
    {
        return $this->ratio;
    }
}

/**
 * @param Collection<int, Importer> $importers
 * @param Collection<int, Score> $scores
 */
function test(Collection $importers, Collection $scores): void
{
    // Averages may be integers, and an empty collection has no average, maximum or minimum.
    assertType('float|int|null', $scores->avg->points());
    assertType('float|int|null', $scores->average->ratio);
    assertType('int|null', $scores->max->points());
    assertType('float|null', $scores->min->ratio);

    // Proxies return what the collection method returns, and an integer sum can overflow to a float.
    assertType('float|int', $scores->sum->points);
    assertType('float|int', $scores->sum->ratio());

    // Upstream has no proxies for these.
    assertType('bool', $importers->doesntContain->isImported());
    assertType('bool', $importers->hasMany->isImported());
    assertType('bool', $importers->hasSole->isImported);
    assertType('App\Importer|null', $importers->last->isImported());
    assertType('App\Importer', $importers->sole->isImported());
    assertType('float|null', $importers->percentage->isImported());
}

class Seeder
{
    public static function run(): bool
    {
        return true;
    }

    public function name(): string
    {
        return '';
    }
}

/**
 * Hypervel: proxies keep the collection's keys and class where the collection method does.
 *
 * @param Collection<string, Score> $scores
 * @param LazyCollection<string, Score> $lazyScores
 * @param EloquentCollection<int, User> $users
 * @param Collection<string, array{active: bool, name: string}> $rows
 * @param Collection<int, class-string<Seeder>> $seeders
 */
function keysAndClasses(Collection $scores, LazyCollection $lazyScores, EloquentCollection $users, Collection $rows, Collection $seeders): void
{
    assertType('Hypervel\Support\Collection<string, int>', $scores->map->points);
    assertType('Hypervel\Support\Collection<string, HigherOrderCollectionProxies\Score>', $scores->filter->points());
    assertType('Hypervel\Support\Collection<string, HigherOrderCollectionProxies\Score>', $scores->sortBy->ratio);
    assertType('Hypervel\Support\Collection<int<0, 1>, Hypervel\Support\Collection<string, HigherOrderCollectionProxies\Score>>', $scores->partition->points);
    assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, HigherOrderCollectionProxies\Score>>', $scores->groupBy->points);
    assertType('Hypervel\Support\Collection<int, HigherOrderCollectionProxies\Score>', $scores->keyBy->points);

    assertType('Hypervel\Support\LazyCollection<string, float>', $lazyScores->map->ratio());
    assertType('Hypervel\Support\LazyCollection<string, HigherOrderCollectionProxies\Score>', $lazyScores->reject->points);

    assertType('Hypervel\Support\Collection<int, string>', $users->map->getAllCapsName());
    assertType('Hypervel\Support\Collection<int<0, 1>, Hypervel\Database\Eloquent\Collection<int, App\User>>', $users->partition->isActive());

    // The proxy reads array items by key, and calls the static methods of class-string items.
    assertType('Hypervel\Support\Collection<string, bool>', $rows->map->active);
    assertType('Hypervel\Support\Collection<string, array{active: bool, name: string}>', $rows->filter->active);
    assertType('Hypervel\Support\Collection<int, bool>', $seeders->map->run());
}

/**
 * @template TKey of array-key
 * @template TValue of Score
 *
 * @extends Collection<TKey, TValue>
 */
class Scores extends Collection
{
    public function points(): void
    {
        // A static collection type means the collection, not the proxy.
        assertType('HigherOrderCollectionProxies\Scores<TKey of (int|string) (class HigherOrderCollectionProxies\Scores, argument), int>', $this->map->points());
    }
}
