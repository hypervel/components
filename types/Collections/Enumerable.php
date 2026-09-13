<?php

declare(strict_types=1);

use Generator;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\LazyCollection;
use stdClass;

use function PHPStan\Testing\assertType;

$collection = new Collection(['first' => 1, 'second' => 2, 'third' => 3]);
$lazy = new LazyCollection(['first' => 1, 'second' => 2, 'third' => 3]);

LazyCollection::make([['name' => 'b'], ['name' => 'a']])->sortBy([['name', false]]);

/** @return Generator<string, int, mixed, void> */
$lazySource = static function (): Generator {
    yield 'first' => 1;
    yield 'second' => 2;
};

assertType('array<string, 1|2|3>', $collection->all());
assertType('Hypervel\Support\Collection<int, int>', Collection::range(1, 3));
assertType('Hypervel\Support\Collection<int, int>', Collection::times(3));
assertType('Hypervel\Support\Collection<int, bool>', Collection::times(3, static fn (int $number): bool => $number > 1));
assertType('Hypervel\Support\LazyCollection<int, int>', LazyCollection::times(3));
assertType('Hypervel\Support\LazyCollection<int, bool>', LazyCollection::times(3, static fn (int $number): bool => $number > 1));
assertType('Hypervel\Support\Collection<int, mixed>', $collection->flatten());
assertType('Hypervel\Support\LazyCollection<int, mixed>', $lazy->flatten());
assertType(
    "Hypervel\\Support\\Collection<'even'|'odd', Hypervel\\Support\\Collection<int, 1|2|3>>",
    $collection->groupBy(static fn (int $value): array => [$value % 2 === 0 ? 'even' : 'odd'])
);

assertType('1|2|3|null', $collection->min());
assertType("'1'|'2'|'3'|null", $collection->min(static fn (int $value): string => (string) $value));
assertType('1|2|3|null', $collection->max());
assertType("'1'|'2'|'3'|null", $collection->max(static fn (int $value): string => (string) $value));

assertType('float|int', $collection->sum(function (int $value, string $key): int {
    assertType('1|2|3', $value);
    assertType('string', $key);

    return $value;
}));
assertType('mixed', $collection->sum('amount'));

assertType('stdClass', $collection->reduceInto(new stdClass, static function (stdClass $result, int $value, string $key): void {
    $result->{$key} = $value;
}));

assertType('1|2|3', $collection->random());
assertType('Hypervel\Support\Collection<int, 1|2|3>', $collection->random(2));
assertType('Hypervel\Support\Collection<string, 1|2|3>', $collection->random(2, true));
assertType('1|2|3', $lazy->random());
assertType('Hypervel\Support\LazyCollection<int, 1|2|3>', $lazy->random(2));
assertType('Hypervel\Support\LazyCollection<string, 1|2|3>', $lazy->random(2, true));
assertType('Hypervel\Support\LazyCollection<string, int>', LazyCollection::make($lazySource));

/**
 * Check shared enumerable return and callback types.
 *
 * @param Enumerable<string, int> $enumerable
 */
function assertEnumerableTypes(Enumerable $enumerable): void
{
    assertType('Hypervel\Support\Enumerable<int, mixed>', $enumerable->flatten());
    assertType('Hypervel\Support\Enumerable<int, int>', $enumerable->random(2));
    assertType('Hypervel\Support\Enumerable<string, int>', $enumerable->random(2, true));
    assertType('Hypervel\Support\Enumerable<int|string, int>', $enumerable->pad(3, 0));
    assertType('float|int', $enumerable->sum(static fn (int $value): int => $value));
    assertType('mixed', $enumerable->sum('amount'));

    $enumerable->tap(function (Enumerable $items): void {
        assertType('array<string, int>', $items->all());
    });
    assertType('Hypervel\Support\Enumerable<int, int>', $enumerable->flatMap(fn (int $value) => new LazyCollection([$value])));

    assertType('Hypervel\Support\Enumerable<(int|string), int>', $enumerable->keyBy(static fn () => Digit::One));
    assertType('Hypervel\Support\Enumerable<string, int>', $enumerable->keyBy(static fn () => new Collection(['key'])));
    assertType('Hypervel\Support\Enumerable<(int|string), int>', $enumerable->countBy(static fn () => Digit::One));
    assertType('Hypervel\Support\Enumerable<(int|string), int>', $enumerable->countBy(static fn (int $value): bool => $value > 1));
    assertType('Hypervel\Support\Enumerable<int, Hypervel\Support\Collection<int, int>>', $enumerable->groupBy(static fn (int $value): bool => $value > 1));
    assertType('Hypervel\Support\Enumerable<string, Hypervel\Support\Collection<string, int>>', $enumerable->groupBy(static fn () => null, preserveKeys: true));
}

assertEnumerableTypes($collection);

/**
 * Check eager collection grouping and key inference.
 *
 * @param Collection<int, User> $collection
 */
function assertCollectionGroupingTypes(Collection $collection): void
{
    assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn (): bool => true));
    assertType('Hypervel\Support\Collection<string, Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn () => null));
    assertType('Hypervel\Support\Collection<string, User>', $collection->keyBy(static fn () => new Collection(['key'])));
    assertType('Hypervel\Support\Collection<(int|string), int>', $collection->countBy(static fn (): bool => true));
}

/**
 * Check lazy collection grouping and key inference.
 *
 * @param LazyCollection<int, User> $collection
 */
function assertLazyCollectionGroupingTypes(LazyCollection $collection): void
{
    assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn (): bool => true));
    assertType('Hypervel\Support\LazyCollection<string, Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn () => null));
    assertType('Hypervel\Support\LazyCollection<string, User>', $collection->keyBy(static fn () => new Collection(['key'])));
    assertType('Hypervel\Support\LazyCollection<(int|string), int>', $collection->countBy(static fn (): bool => true));
}
