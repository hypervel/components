<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data;

use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;

/**
 * @param  Collection<int, int>  $ints
 * @param  PlainCollection<int, int>  $plain
 * @param  EloquentCollection<int, Model>  $models
 * @param  LazyCollection<int, Model|string>  $innerUnion
 * @param  EloquentCollection<int, Model>|Collection<string, string>  $outerUnion
 * @param  ToArrayOverridingCollection<int, int>  $overriding
 * @param  Enumerable<int, int>  $enumerable
 */
function test(
    mixed $unknown,
    Collection $ints,
    PlainCollection $plain,
    EloquentCollection $models,
    LazyCollection $innerUnion,
    Enumerable $outerUnion,
    ToArrayOverridingCollection $overriding,
    Enumerable $enumerable,
): void {
    // should be reported
    $ints->toArray();
    $plain->toArray();

    // should not be reported
    $unknown->toArray();
    $models->toArray();
    $innerUnion->toArray();
    $outerUnion->toArray();
    $overriding->toArray();
    $enumerable->toArray();
}

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends Collection<TKey, TValue>
 */
class PlainCollection extends Collection
{
}

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends Collection<TKey, TValue>
 */
class ToArrayOverridingCollection extends Collection
{
    public function toArray(): array
    {
        return ['count' => $this->count()];
    }
}
