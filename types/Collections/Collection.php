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
    assertType('float|int', $enumerable->sum(static fn (int $value): int => $value));
    assertType('mixed', $enumerable->sum('amount'));

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
    assertType('Hypervel\Support\Collection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy('name'));
    assertType('Hypervel\Support\Collection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy('name', true));
    assertType('Hypervel\Support\Collection<(int|string), Hypervel\Support\Collection<int, mixed>>', $collection->groupBy(['name', 'email']));
    assertType("Hypervel\\Support\\Collection<'foo', Hypervel\\Support\\Collection<int, User>>", $collection->groupBy(function ($user, $int) {
        assertType('User', $user);
        assertType('int', $int);

        return 'foo';
    }));
    assertType('Hypervel\Support\Collection<0, Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn ($user) => 0));
    assertType('Hypervel\Support\Collection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn ($user) => Digit::One));
    assertType('Hypervel\Support\Collection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn ($user) => NamedDigit::One));
    assertType('Hypervel\Support\Collection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn ($user) => NumberedDigit::One));

    assertType("Hypervel\\Support\\Collection<'foo', Hypervel\\Support\\Collection<'bar', User>>", $collection->keyBy(fn ($user) => 'bar')->groupBy(function ($user) {
        return 'foo';
    }, preserveKeys: true));

    assertType('Hypervel\Support\Collection<(int|string), User>', $collection->keyBy('name'));
    assertType("Hypervel\\Support\\Collection<'foo', User>", $collection->keyBy(function ($user, $int) {
        assertType('User', $user);
        assertType('int', $int);

        return 'foo';
    }));
    assertType('Hypervel\Support\Collection<0, User>', $collection->keyBy(static fn ($user): int => 0));
    assertType('Hypervel\Support\Collection<(int|string), User>', $collection->keyBy(static fn ($user) => Digit::One));
    assertType('Hypervel\Support\Collection<(int|string), User>', $collection->keyBy(static fn ($user) => NamedDigit::One));
    assertType('Hypervel\Support\Collection<(int|string), User>', $collection->keyBy(static fn ($user) => NumberedDigit::One));

    assertType('Hypervel\Support\Collection<(int|string), int>', $collection::make([1])->countBy());
    assertType('Hypervel\Support\Collection<(int|string), int>', $collection::make(['string' => 'string'])->countBy('string'));
    assertType('Hypervel\Support\Collection<(int|string), int>', $collection::make([new User])->countBy('email'));
    assertType('Hypervel\Support\Collection<(int|string), int>', $collection::make([new User])->countBy(static fn ($user) => 'email'));
    assertType('Hypervel\Support\Collection<(int|string), int>', $collection::make([new User])->countBy(static fn ($user) => 0));
    assertType('Hypervel\Support\Collection<(int|string), int>', $collection::make([new User])->countBy(static fn ($user) => Digit::One));
    assertType('Hypervel\Support\Collection<(int|string), int>', $collection::make([new User])->countBy(static fn ($user) => NamedDigit::One));
    assertType('Hypervel\Support\Collection<(int|string), int>', $collection::make(['string'])->countBy(function ($string, $int) {
        assertType('string', $string);
        assertType('int', $int);

        return $string;
    }));

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
    assertType('Hypervel\Support\LazyCollection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy('name'));
    assertType('Hypervel\Support\LazyCollection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy('name', true));
    assertType('Hypervel\Support\LazyCollection<(int|string), Hypervel\Support\Collection<int, mixed>>', $collection->groupBy(['name', 'email']));
    assertType("Hypervel\\Support\\LazyCollection<'foo', Hypervel\\Support\\Collection<int, User>>", $collection->groupBy(function ($user, $int) {
        assertType('User', $user);
        assertType('int', $int);

        return 'foo';
    }));
    assertType('Hypervel\Support\LazyCollection<0, Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn ($user) => 0));
    assertType('Hypervel\Support\LazyCollection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn ($user) => Digit::One));
    assertType('Hypervel\Support\LazyCollection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn ($user) => NamedDigit::One));
    assertType('Hypervel\Support\LazyCollection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn ($user) => NumberedDigit::One));

    assertType("Hypervel\\Support\\LazyCollection<'foo', Hypervel\\Support\\Collection<'bar', User>>", $collection->keyBy(fn ($user) => 'bar')->groupBy(function ($user) {
        return 'foo';
    }, preserveKeys: true));

    assertType('Hypervel\Support\LazyCollection<(int|string), User>', $collection->keyBy('name'));
    assertType("Hypervel\\Support\\LazyCollection<'foo', User>", $collection->keyBy(function ($user, $int) {
        assertType('User', $user);
        assertType('int', $int);

        return 'foo';
    }));
    assertType('Hypervel\Support\LazyCollection<0, User>', $collection->keyBy(static fn ($user): int => 0));
    assertType('Hypervel\Support\LazyCollection<(int|string), User>', $collection->keyBy(static fn ($user) => Digit::One));
    assertType('Hypervel\Support\LazyCollection<(int|string), User>', $collection->keyBy(static fn ($user) => NamedDigit::One));
    assertType('Hypervel\Support\LazyCollection<(int|string), User>', $collection->keyBy(static fn ($user) => NumberedDigit::One));

    assertType('Hypervel\Support\LazyCollection<(int|string), int>', $collection::make([1])->countBy());
    assertType('Hypervel\Support\LazyCollection<(int|string), int>', $collection::make(['string' => 'string'])->countBy('string'));
    assertType('Hypervel\Support\LazyCollection<(int|string), int>', $collection::make([new User])->countBy('email'));
    assertType('Hypervel\Support\LazyCollection<(int|string), int>', $collection::make([new User])->countBy(static fn ($user) => 'email'));
    assertType('Hypervel\Support\LazyCollection<(int|string), int>', $collection::make([new User])->countBy(static fn ($user) => 0));
    assertType('Hypervel\Support\LazyCollection<(int|string), int>', $collection::make([new User])->countBy(static fn ($user) => Digit::One));
    assertType('Hypervel\Support\LazyCollection<(int|string), int>', $collection::make([new User])->countBy(static fn ($user) => NamedDigit::One));
    assertType('Hypervel\Support\LazyCollection<(int|string), int>', $collection::make(['string'])->countBy(function ($string, $int) {
        assertType('string', $string);
        assertType('int', $int);

        return $string;
    }));

    assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn (): bool => true));
    assertType('Hypervel\Support\LazyCollection<string, Hypervel\Support\Collection<int, User>>', $collection->groupBy(static fn () => null));
    assertType('Hypervel\Support\LazyCollection<string, User>', $collection->keyBy(static fn () => new Collection(['key'])));
    assertType('Hypervel\Support\LazyCollection<(int|string), int>', $collection->countBy(static fn (): bool => true));
}

enum Digit
{
    case One;
    case Two;
    case Three;
}

enum NamedDigit: string
{
    case One = 'one';
    case Two = 'two';
    case Three = 'three';
}

enum NumberedDigit: int
{
    case One = 1;
    case Two = 2;
    case Three = 3;
}
