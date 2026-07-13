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
 * @param Enumerable<string, int> $enumerable
 */
function assertEnumerableTypes(Enumerable $enumerable): void
{
    assertType('Hypervel\Support\Enumerable<int, int>', $enumerable->random(2));
    assertType('Hypervel\Support\Enumerable<string, int>', $enumerable->random(2, true));
    assertType('float|int', $enumerable->sum(static fn (int $value): int => $value));
    assertType('mixed', $enumerable->sum('amount'));
}

assertEnumerableTypes($collection);
