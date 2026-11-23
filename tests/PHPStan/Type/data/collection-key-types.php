<?php

namespace CollectionKeyTypes;

use App\User;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;

use function PHPStan\Testing\assertType;

/**
 * @param Collection<string, int>       $stringKeys
 * @param Collection<int, int>          $intKeys
 * @param Collection<array-key, mixed>  $arrayKeys
 * @param LazyCollection<string, int>   $lazy
 * @param EloquentCollection<int, User> $users
 * @param array<string, int>            $map
 * @param array<string, User>           $keyedUsers
 */
function test(
    Collection $stringKeys,
    Collection $intKeys,
    Collection $arrayKeys,
    LazyCollection $lazy,
    EloquentCollection $users,
    array $map,
    array $keyedUsers,
): void {
    assertType('Hypervel\Support\Collection<string, int>', $stringKeys->merge($map));
    assertType('Hypervel\Support\Collection<int, int>', $intKeys->merge([1]));
    assertType('Hypervel\Support\Collection<(int|string), mixed>', $arrayKeys->merge([1]));
    // Hypervel: mergeRecursive() turns values under a repeated string key into arrays.
    assertType('Hypervel\Support\Collection<string, array<mixed>|int>', $stringKeys->mergeRecursive($map));
    assertType('Hypervel\Support\Collection<(int|string), mixed>', $arrayKeys->mergeRecursive([1]));
    assertType('Hypervel\Support\LazyCollection<string, int>', $lazy->merge($map));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->merge($keyedUsers));

    assertType('Hypervel\Support\Collection<int|string, int>', $stringKeys->concat($map));
    assertType('Hypervel\Support\Collection<int, int>', $intKeys->concat($map));
    assertType('Hypervel\Support\Collection<(int|string), mixed>', $arrayKeys->concat($map));
    // Hypervel: the lazy concat() calls values(), as in Laravel, so its keys are always integers.
    assertType('Hypervel\Support\LazyCollection<int, int>', $lazy->concat($map));

    assertType('Hypervel\Support\Collection<string, int>', $intKeys->flatMap(fn (int $value) => $map));
    assertType('Hypervel\Support\Collection<int, int>', $stringKeys->flatMap(fn (int $value) => [$value]));
    assertType('Hypervel\Support\LazyCollection<int, int>', $lazy->flatMap(fn (int $value) => [$value]));

    assertType('Hypervel\Support\Collection<int, int>', $stringKeys->shuffle());
    assertType('Hypervel\Support\Collection<int, int>', $stringKeys->multiply(2));
    assertType('Hypervel\Support\LazyCollection<int, int>', $lazy->shuffle());
    assertType('Hypervel\Support\LazyCollection<int, int>', $lazy->multiply(2));

    assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<string, int>>', $stringKeys->split(2));
    assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<(int|string), mixed>>', $arrayKeys->split(2));
    assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\Collection<string, int>>', $lazy->split(2));
}

/**
 * @param Collection<string, int>      $appended
 * @param Collection<string, int>      $prepended
 * @param Collection<array-key, mixed> $pushedArrayKeys
 * @param Collection<string, int>      $shifted
 * @param Collection<array-key, mixed> $shiftedArrayKeys
 * @param Collection<string, int>      $spliced
 * @param Collection<string, int>      $put
 */
function mutations(
    Collection $appended,
    Collection $prepended,
    Collection $pushedArrayKeys,
    Collection $shifted,
    Collection $shiftedArrayKeys,
    Collection $spliced,
    Collection $put,
): void
{
    $appended->push(1);
    assertType('Hypervel\Support\Collection<int|string, int>', $appended);

    $prepended->prepend(1, 'key');
    assertType('Hypervel\Support\Collection<string, int>', $prepended);
    $prepended->prepend(1);
    assertType('Hypervel\Support\Collection<int|string, int>', $prepended);

    $pushedArrayKeys->add(1);
    assertType('Hypervel\Support\Collection<(int|string), mixed>', $pushedArrayKeys);

    $shifted->shift();
    assertType('Hypervel\Support\Collection<string, int>', $shifted);

    $shiftedArrayKeys->shift();
    assertType('Hypervel\Support\Collection<(int|string), mixed>', $shiftedArrayKeys);

    assertType('Hypervel\Support\Collection<string, int>', $spliced->splice(1));
    assertType('Hypervel\Support\Collection<string, int>', $spliced);

    assertType('int|null', $put->getOrPut(1, null));
    assertType('Hypervel\Support\Collection<1|string, int|null>', $put);
}
