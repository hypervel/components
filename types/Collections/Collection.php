<?php

declare(strict_types=1);

use ArrayIterator;
use Exception;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use SortDirection;
use Traversable;

use function PHPStan\Testing\assertType;

/** @implements Arrayable<int, User> */
class Users implements Arrayable
{
    /**
     * Get the users as an array.
     */
    public function toArray(): array
    {
        return [new User];
    }
}

$collection = collect([new User]);
$arrayable = new Users;
/** @var iterable<int, int> $iterable */
$iterable = [1];
/** @var Traversable<int, string> $traversable */
$traversable = new ArrayIterator(['string']);

$associativeCollection = collect(['John' => new User]);

class Invokable
{
    /**
     * Return the name.
     */
    public function __invoke(): string
    {
        return 'Taylor';
    }
}
$invokable = new Invokable;

assertType('Hypervel\Support\Collection<int, User>', $collection);

assertType('Hypervel\Support\Collection<int, string>', collect(['string']));
assertType('Hypervel\Support\Collection<string, User>', collect(['string' => new User]));
assertType('Hypervel\Support\Collection<int, User>', collect($arrayable));
assertType('Hypervel\Support\Collection<int, User>', collect($collection));
assertType('Hypervel\Support\Collection<int, int>', collect($iterable));
assertType('Hypervel\Support\Collection<int, string>', collect($traversable));

assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string']));
assertType('Hypervel\Support\Collection<string, User>', $collection::make(['string' => new User]));
assertType('Hypervel\Support\Collection<int, User>', $collection::make($arrayable));
assertType('Hypervel\Support\Collection<int, User>', $collection::make($collection));
assertType('Hypervel\Support\Collection<int, int>', $collection::make($iterable));
assertType('Hypervel\Support\Collection<int, string>', $collection::make($traversable));

assertType('Hypervel\Support\Collection<int, User>', $collection::times(10, function ($int) {
    // assertType('int', $int);

    return new User;
}));

assertType('Hypervel\Support\Collection<int, User>', $collection::times(10, function () {
    return new User;
}));

assertType('Hypervel\Support\Collection<int, User>', $collection->each(function ($user) {
    assertType('User', $user);
}));

assertType('Hypervel\Support\Collection<int, int>', $collection::range(1, 100));

assertType('Hypervel\Support\Collection<(int|string), string>', $collection::wrap('string'));
assertType('Hypervel\Support\Collection<(int|string), User>', $collection::wrap(new User));

assertType('Hypervel\Support\Collection<(int|string), string>', $collection::wrap(['string']));
assertType('Hypervel\Support\Collection<(int|string), User>', $collection::wrap(['string' => new User]));

assertType("array<0, 'string'>", $collection::unwrap(['string']));
assertType('array<int, User>', $collection::unwrap(
    $collection
));
assertType("'string'", Collection::unwrap('string'));
assertType('null', Collection::unwrap(null));

/**
 * Check unwrapping an array or collection parameter.
 *
 * @param array<string, int>|Collection<string, int> $items
 */
function assertUnwrapUnion(array|Collection $items): void
{
    assertType('array<string, int>', Collection::unwrap($items));
}

assertType('Hypervel\Support\Collection<int, User>', $collection::empty());

assertType('float|int|null', $collection->average());
assertType('float|int|null', $collection->average('string'));
assertType('float|int|null', $collection->average(function ($user) {
    assertType('User', $user);

    return 1;
}));
assertType('float|int|null', $collection->average(function ($user) {
    assertType('User', $user);

    return 0.1;
}));

assertType('float|int|null', $collection->median());
assertType('float|int|null', $collection->median('string'));
assertType('float|int|null', $collection->median(['string']));

assertType('array<int, float|int>|null', $collection->mode());
assertType('array<int, float|int>|null', $collection->mode('string'));
assertType('array<int, float|int>|null', $collection->mode(['string']));

assertType('Hypervel\Support\Collection<int, mixed>', $collection->collapse());

assertType('bool', $collection->some(function ($user) {
    assertType('User', $user);

    return true;
}));
assertType('bool', $collection::make(['string'])->some('string', '=', 'string'));

assertType('bool', $collection->containsStrict(function ($user) {
    assertType('User', $user);

    return true;
}));
assertType('bool', $collection::make(['string'])->containsStrict('string', 'string'));
assertType('bool', $collection::make([[1]])->containsStrict(0));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->lazy());

assertType('float|int|null', $collection->avg());
assertType('float|int|null', $collection->avg('string'));
assertType('float|int|null', $collection->avg(function ($user) {
    assertType('User', $user);

    return 1;
}));
assertType('float|int|null', $collection->avg(function ($user) {
    assertType('User', $user);

    return 0.1;
}));

assertType('bool', $collection->contains(function ($user) {
    assertType('User', $user);

    return true;
}));
assertType('bool', $collection->contains(function ($user, $int) {
    assertType('int', $int);
    assertType('User', $user);

    return true;
}));
assertType('bool', $collection::make(['string'])->contains('string', '=', 'string'));

assertType('Hypervel\Support\Collection<int, array<int, string|User>>', $collection->crossJoin($collection::make(['string'])));
assertType('Hypervel\Support\Collection<int, array<int, int|User>>', $collection->crossJoin([1, 2]));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([3, 4])->diff([1, 2]));
assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string-1'])->diff(['string-2']));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([3, 4])->diffUsing([1, 2], function ($intA, $intB) {
    assertType('int', $intA);
    assertType('int', $intB);

    return -1;
}));
assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string-1'])->diffUsing(['string-2'], function ($stringA, $stringB) {
    assertType('string', $stringA);
    assertType('string', $stringB);

    return -1;
}));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([3, 4])->diffAssoc([1, 2]));
assertType('Hypervel\Support\Collection<string, string>', $collection::make(['string' => 'string'])->diffAssoc(['string' => 'string']));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([3, 4])->diffAssocUsing([1, 2], function ($intA, $intB) {
    assertType('int', $intA);
    assertType('int', $intB);

    return -1;
}));
assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string-1'])->diffAssocUsing(['string-2'], function ($intA, $intB) {
    assertType('int', $intA);
    assertType('int', $intB);

    return -1;
}));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([3, 4])->diffKeys([1, 2]));
assertType('Hypervel\Support\Collection<string, string>', $collection::make(['string' => 'string'])->diffKeys(['string' => 'string']));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([3, 4])->diffKeysUsing([1, 2], function ($intA, $intB) {
    assertType('int', $intA);
    assertType('int', $intB);

    return -1;
}));
assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string-1'])->diffKeysUsing(['string-2'], function ($intA, $intB) {
    assertType('int', $intA);
    assertType('int', $intB);

    return -1;
}));

assertType('Hypervel\Support\Collection<string, string>', $collection::make(['string' => 'string'])
    ->duplicates());
assertType('Hypervel\Support\Collection<int, User>', $collection->duplicates('name', true));
assertType('Hypervel\Support\Collection<int, int|string>', $collection::make([3, 'string'])
    ->duplicates(function ($intOrString) {
        assertType('int|string', $intOrString);

        return true;
    }));

assertType('Hypervel\Support\Collection<string, string>', $collection::make(['string' => 'string'])
    ->duplicatesStrict());
assertType('Hypervel\Support\Collection<int, User>', $collection->duplicatesStrict('name'));
assertType('Hypervel\Support\Collection<int, int|string>', $collection::make([3, 'string'])
    ->duplicatesStrict(function ($intOrString) {
        assertType('int|string', $intOrString);

        return true;
    }));

assertType('Hypervel\Support\Collection<int, User>', $collection->each(function ($user) {
    assertType('User', $user);

    return null;
}));
assertType('Hypervel\Support\Collection<int, User>', $collection->each(function ($user) {
    assertType('User', $user);
}));
assertType('Hypervel\Support\Collection<int, User>', $collection->each(function ($user, $int) {
    assertType('int', $int);
    assertType('User', $user);
}));

assertType('Hypervel\Support\Collection<int, array{string}>', $collection::make([['string']])
    ->eachSpread(function ($int, $string) {
        // assertType('int', $int);
        // assertType('int', $string);

        return null;
    }));
assertType('Hypervel\Support\Collection<int, array{int, string}>', $collection::make([[1, 'string']])
    ->eachSpread(function ($int, $string) {
        // assertType('int', $int);
        // assertType('int', $string);
    }));

assertType('bool', $collection->every(function ($user, $int) {
    assertType('int', $int);
    assertType('User', $user);

    return true;
}));
assertType('bool', $collection::make(['string'])->every('string', '=', 'string'));

assertType('Hypervel\Support\Collection<string, string>', $collection::make(['string' => 'string'])->except(['string']));
assertType('Hypervel\Support\Collection<int, User>', $collection->except([1]));
assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string'])
    ->except([1]));

assertType('Hypervel\Support\Collection<int, User>', $collection->filter());
assertType('Hypervel\Support\Collection<int, User>', $collection->filter(function ($user) {
    assertType('User', $user);

    return true;
}));

assertType('Hypervel\Support\Collection<int, User>|true', $collection->when(true, function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return true;
}));
assertType('Hypervel\Support\Collection<int, User>|null', $collection->when(true, function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);
}));
assertType("'string'|Hypervel\\Support\\Collection<int, User>", $collection->when(true, function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return 'string';
}));
assertType('Hypervel\Support\Collection<int, User>|null', $collection->when('Taylor', function ($collection, $name) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);
    assertType("'Taylor'", $name);
}));
assertType(
    'Hypervel\Support\Collection<int, User>|null',
    $collection->when(
        'Taylor',
        function ($collection, $name) {
            assertType('Hypervel\Support\Collection<int, User>', $collection);
            assertType("'Taylor'", $name);
        },
        function ($collection, $name) {
            assertType('Hypervel\Support\Collection<int, User>', $collection);
            assertType("'Taylor'", $name);
        }
    )
);
assertType('Hypervel\Support\Collection<int, User>|null', $collection->when(fn () => 'Taylor', function ($collection, $name) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);
    assertType("'Taylor'", $name);
}));
assertType(
    'Hypervel\Support\Collection<int, User>|null',
    $collection->when(
        function ($collection) {
            assertType('Hypervel\Support\Collection<int, User>', $collection);

            return 14;
        },
        function ($collection, $count) {
            assertType('Hypervel\Support\Collection<int, User>', $collection);
            assertType('14', $count);
        },
        function ($collection, $count) {
            assertType('Hypervel\Support\Collection<int, User>', $collection);
            assertType('14', $count);
        }
    )
);

assertType('Hypervel\Support\Collection<int, User>|null', $collection->when($invokable, function ($collection, $param) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);
    assertType('Invokable', $param);
}));

assertType('Hypervel\Support\Collection<int, User>|true', $collection->whenEmpty(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return true;
}));
assertType('Hypervel\Support\Collection<int, User>|null', $collection->whenEmpty(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);
}));
assertType("'string'|Hypervel\\Support\\Collection<int, User>", $collection->whenEmpty(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return 'string';
}));

assertType('Hypervel\Support\Collection<int, User>|true', $collection->whenNotEmpty(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return true;
}));
assertType('Hypervel\Support\Collection<int, User>|null', $collection->whenNotEmpty(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);
}));
assertType("'string'|Hypervel\\Support\\Collection<int, User>", $collection->whenNotEmpty(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return 'string';
}));

assertType('Hypervel\Support\Collection<int, User>|true', $collection->unless(true, function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return true;
}));
assertType('Hypervel\Support\Collection<int, User>|null', $collection->unless(true, function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);
}));
assertType("'string'|Hypervel\\Support\\Collection<int, User>", $collection->unless(true, function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return 'string';
}));
assertType('Hypervel\Support\Collection<int, User>|null', $collection->unless('Taylor', function ($collection, $name) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);
    assertType("'Taylor'", $name);
}));
assertType(
    'Hypervel\Support\Collection<int, User>|null',
    $collection->unless(
        'Taylor',
        function ($collection, $name) {
            assertType('Hypervel\Support\Collection<int, User>', $collection);
            assertType("'Taylor'", $name);
        },
        function ($collection, $name) {
            assertType('Hypervel\Support\Collection<int, User>', $collection);
            assertType("'Taylor'", $name);
        }
    )
);
assertType('Hypervel\Support\Collection<int, User>|null', $collection->unless(fn () => 'Taylor', function ($collection, $name) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);
    assertType("'Taylor'", $name);
}));
assertType(
    'Hypervel\Support\Collection<int, User>|null',
    $collection->unless(
        function ($collection) {
            assertType('Hypervel\Support\Collection<int, User>', $collection);

            return 14;
        },
        function ($collection, $count) {
            assertType('Hypervel\Support\Collection<int, User>', $collection);
            assertType('14', $count);
        },
        function ($collection, $count) {
            assertType('Hypervel\Support\Collection<int, User>', $collection);
            assertType('14', $count);
        }
    )
);

assertType('Hypervel\Support\Collection<int, User>|null', $collection->unless($invokable, function ($collection, $param) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);
    assertType('Invokable', $param);
}));

assertType('Hypervel\Support\Collection<int, User>|true', $collection->unlessEmpty(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return true;
}));
assertType('Hypervel\Support\Collection<int, User>|null', $collection->unlessEmpty(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);
}));
assertType("'string'|Hypervel\\Support\\Collection<int, User>", $collection->unlessEmpty(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return 'string';
}));

assertType('Hypervel\Support\Collection<int, User>|true', $collection->unlessNotEmpty(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return true;
}));
assertType('Hypervel\Support\Collection<int, User>|null', $collection->unlessNotEmpty(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);
}));
assertType("'string'|Hypervel\\Support\\Collection<int, User>", $collection->unlessNotEmpty(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return 'string';
}));

assertType('Hypervel\Support\Collection<int, array{string: string}>', $collection::make([['string' => 'string']])
    ->where('string'));
assertType('Hypervel\Support\Collection<int, array{string: string}>', $collection::make([['string' => 'string']])
    ->where('string', '=', 'string'));
assertType('Hypervel\Support\Collection<int, array{string: string}>', $collection::make([['string' => 'string']])
    ->where('string', 'string'));

assertType('Hypervel\Support\Collection<int, User>', $collection->whereNull());
assertType('Hypervel\Support\Collection<int, User>', $collection->whereNull('foo'));

assertType('Hypervel\Support\Collection<int, User>', $collection->whereNotNull());
assertType('Hypervel\Support\Collection<int, User>', $collection->whereNotNull('foo'));

assertType('Hypervel\Support\Collection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereStrict('string', 2));

assertType('Hypervel\Support\Collection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereIn('string', [2]));

assertType('Hypervel\Support\Collection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereInStrict('string', [2]));

assertType('Hypervel\Support\Collection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereBetween('string', [1, 3]));

assertType('Hypervel\Support\Collection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereNotBetween('string', [1, 3]));

assertType('Hypervel\Support\Collection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereNotIn('string', [2]));

assertType('Hypervel\Support\Collection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereNotInStrict('string', [2]));

assertType('Hypervel\Support\Collection<int, User>', $collection::make([new User, 1])
    ->whereInstanceOf(User::class));

assertType('Hypervel\Support\Collection<int, Exception|User>', $collection::make([new User, 1])
    ->whereInstanceOf([User::class, Exception::class]));

assertType('User|null', $collection->first());
assertType('User|null', $collection->first(function ($user) {
    assertType('User', $user);

    return true;
}));
assertType("'string'|User", $collection->first(function ($user) {
    assertType('User', $user);

    return false;
}, 'string'));
assertType("'string'|User", $collection->first(null, function () {
    return 'string';
}));
if ($collection->isNotEmpty()) {
    assertType('User', $collection->first());
    assertType("'foo'|User", $collection->first(null, 'foo'));
} else {
    assertType('null', $collection->first());
    assertType("'foo'|User", $collection->first(null, 'foo'));
}
if ($collection->isEmpty()) {
    assertType('null', $collection->first());
    assertType("'foo'|User", $collection->first(null, 'foo'));
} else {
    assertType('User', $collection->first());
    assertType("'foo'|User", $collection->first(null, 'foo'));
}

assertType('Hypervel\Support\Collection<int, mixed>', $collection->flatten());
assertType('Hypervel\Support\Collection<int, mixed>', $collection::make(['string' => 'string'])->flatten(4));

assertType('User|null', $collection->firstWhere('string', 'string'));
assertType('User|null', $collection->firstWhere('string', 'string', 'string'));

assertType('User|null', $collection->value('string'));
assertType("'string'|User", $collection->value('string', 'string'));
assertType("'string'|User", $collection->value('string', fn () => 'string'));

assertType('Hypervel\Support\Collection<string, int>', $collection::make(['string'])->flip());

assertType('Hypervel\Support\Collection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy('name'));
assertType('Hypervel\Support\Collection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy('name', true));
assertType('Hypervel\Support\Collection<(int|string), Hypervel\Support\Collection<(int|string), mixed>>', $collection->groupBy(['name', 'email']));
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

assertType('bool', $collection->has(0));
assertType('bool', $collection->has([0, 1]));

assertType('string', $collection->implode(function ($user, $index) {
    assertType('User', $user);
    assertType('int', $index);

    return 'string';
}));

assertType('Hypervel\Support\Collection<int, User>', $collection->intersect([new User]));

assertType('Hypervel\Support\Collection<int, User>', $collection->intersectByKeys([new User]));

assertType('Hypervel\Support\Collection<int, int>', $collection->keys());

assertType('User|null', $collection->last());
assertType('User|null', $collection->last(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));
assertType("'string'|User", $collection->last(function () {
    return true;
}, 'string'));
assertType("'string'|User", $collection->last(null, function () {
    return 'string';
}));

assertType('Hypervel\Support\Collection<int, int>', $collection->map(function () {
    return 1;
}));
assertType('Hypervel\Support\Collection<int, string>', $collection->map(function () {
    return 'string';
}));

assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string'])
    ->map(function ($string, $int) {
        assertType('string', $string);
        assertType('int', $int);

        return (string) $string;
    }));

assertType('Hypervel\Support\Collection<int, mixed>', $collection::make(['string'])
    ->mapSpread(function () {
        return 'string';
    }));

assertType('Hypervel\Support\Collection<int, mixed>', $collection::make(['string'])
    ->mapSpread(function () {
        return 1;
    }));

assertType('Hypervel\Support\Collection<int, mixed>', Collection::make([[0, 1], [2, 3]])
    ->mapSpread(fn (int $even, int $odd): int => $even + $odd));

assertType('Hypervel\Support\Collection<string, array<int, int>>', $collection::make(['string', 'string'])
    ->mapToDictionary(function ($stringValue, $stringKey) {
        assertType('string', $stringValue);
        assertType('int', $stringKey);

        return ['string' => 1];
    }));

assertType('Hypervel\Support\Collection<string, Hypervel\Support\Collection<int, int>>', $collection::make(['string', 'string'])
    ->mapToGroups(function ($stringValue, $stringKey) {
        assertType('string', $stringValue);
        assertType('int', $stringKey);

        return ['string' => 1];
    }));

assertType('Hypervel\Support\Collection<string, int>', $collection::make(['string'])
    ->mapWithKeys(function ($string, $int) {
        assertType('string', $string);
        assertType('int', $int);

        return ['string' => 1];
    }));

assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string'])
    ->flatMap(function ($string, $int) {
        assertType('string', $string);
        assertType('int', $int);

        return [0 => 'string'];
    }));

assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string'])
    ->flatMap(fn ($string) => new LazyCollection([$string])));

assertType('Hypervel\Support\Collection<int, User>', $collection->mapInto(User::class));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->merge([2]));
assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string'])->merge(['string']));

assertType('Hypervel\Support\Collection<int, int|string>', $collection::make([1])->merge(['string']));
assertType('Hypervel\Support\Collection<int, int|string>', $collection::make(['string'])->merge([1]));

assertType('Hypervel\Support\Collection<int, int|string>', $collection::make([1])->mergeRecursive([2 => 'string']));
assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string'])->mergeRecursive(['string']));

assertType('Hypervel\Support\Collection<string, int>', $collection::make(['string' => 'string'])->combine([2]));
assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->combine([1]));
assertType('Hypervel\Support\Collection<string, string>', $collection::make(['string'])->combine(['string']));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->union([1]));
assertType('Hypervel\Support\Collection<string, string>', $collection::make(['string' => 'string'])->union(['string' => 'string']));

assertType('null', $collection::make()->min());
assertType('int|null', $collection::make([1])->min());
assertType('mixed', $collection::make([1])->min('string'));
assertType('mixed', $collection::make(['string' => 1])->min('string'));
assertType("'foo'|null", $collection::make([1])->min(function ($int) {
    assertType('int', $int);

    return 'foo';
}));
assertType('mixed', $collection::make([new User])->min('id'));

assertType('null', $collection::make()->max());
assertType('int|null', $collection::make([1])->max());
assertType('mixed', $collection::make([1])->max('string'));
assertType("'foo'|null", $collection::make([1])->max(function ($int) {
    assertType('int', $int);

    return 'foo';
}));
assertType('mixed', $collection::make([new User])->max('id'));

assertType('Hypervel\Support\Collection<int, User>', $collection->nth(1, 2));

assertType('Hypervel\Support\Collection<string, string>', $collection::make(['string' => 'string'])->only(['string']));
assertType('Hypervel\Support\Collection<int, User>', $collection->only([1]));
assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string'])
    ->only([1]));

assertType('Hypervel\Support\Collection<int, User>', $collection->forPage(1, 2));

assertType('Hypervel\Support\Collection<int<0, 1>, Hypervel\Support\Collection<int, User>>', $collection->partition(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));
assertType('Hypervel\Support\Collection<int<0, 1>, Hypervel\Support\Collection<int, string>>', $collection::make(['string'])->partition('string', '=', 'string'));
assertType('Hypervel\Support\Collection<int<0, 1>, Hypervel\Support\Collection<int, string>>', $collection::make(['string'])->partition('string', 'string'));
assertType('Hypervel\Support\Collection<int<0, 1>, Hypervel\Support\Collection<int, string>>', $collection::make(['string'])->partition('string'));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->concat([2]));
assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string'])->concat(['string']));
assertType('Hypervel\Support\Collection<int, int|string>', $collection::make([1])->concat(['string']));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->random(2));
assertType('string', $collection::make(['string'])->random());

assertType('1|null', $collection
    ->reduce(function ($null, $user) {
        assertType('User', $user);
        assertType('1|null', $null);

        return 1;
    }));
assertType('0|1', $collection
    ->reduce(function ($int, $user) {
        assertType('User', $user);
        assertType('0|1', $int);

        return 1;
    }, 0));
assertType('0|1', $collection
    ->reduce(function ($int, $user, $key) {
        assertType('User', $user);
        assertType('0|1', $int);
        assertType('int', $key);

        return 1;
    }, 0));

assertType('1|null', $collection
    ->reduceWithKeys(function ($null, $user) {
        assertType('User', $user);
        assertType('1|null', $null);

        return 1;
    }));
assertType('0|1', $collection
    ->reduceWithKeys(function ($int, $user) {
        assertType('User', $user);
        assertType('0|1', $int);

        return 1;
    }, 0));
assertType('0|1', $collection
    ->reduceWithKeys(function ($int, $user, $key) {
        assertType('User', $user);
        assertType('0|1', $int);
        assertType('int', $key);

        return 1;
    }, 0));
assertType("'bar'|'foo'", $collection::make([])->reduce(static fn (): string => 'foo', 'bar'));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->replace([1]));
assertType('Hypervel\Support\Collection<int, User>', $collection->replace([new User]));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->replaceRecursive([1]));
assertType('Hypervel\Support\Collection<int, User>', $collection->replaceRecursive([new User]));

assertType('Hypervel\Support\Collection<int, User>', $collection->reverse());

// assertType('int|bool', $collection::make([1])->search(2));
// assertType('string|bool', $collection::make(['string' => 'string'])->search('string'));
// assertType('int|bool', $collection->search(function ($user, $int) {
//     assertType('User', $user);
//    assertType('int', $int);
//
//    return true;
// }));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->shuffle());
assertType('Hypervel\Support\Collection<int, User>', $collection->shuffle());

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->skip(1));
assertType('Hypervel\Support\Collection<int, User>', $collection->skip(1));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->skipUntil(1));
assertType('Hypervel\Support\Collection<int, User>', $collection->skipUntil(new User));
assertType('Hypervel\Support\Collection<int, User>', $collection->skipUntil(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->skipWhile(1));
assertType('Hypervel\Support\Collection<int, User>', $collection->skipWhile(new User));
assertType('Hypervel\Support\Collection<int, User>', $collection->skipWhile(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->slice(1));
assertType('Hypervel\Support\Collection<int, User>', $collection->slice(1, 2));

assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, User>>', $collection->split(3));
assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, int>>', $collection::make([1])->split(3));

assertType('string', $collection::make(['string' => 'string'])->sole('string', 'string'));
assertType('string', $collection::make(['string' => 'string'])->sole('string', '=', 'string'));
assertType('User', $collection->sole(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));

assertType('User', $collection->firstOrFail());
assertType('User', $collection->firstOrFail('string', 'string'));
assertType('User', $collection->firstOrFail('string', '=', 'string'));
assertType('User', $collection->firstOrFail(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));

assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, string>>', $collection::make(['string'])->chunk(1));
assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, User>>', $collection->chunk(2));
assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<string, User>>', $associativeCollection->chunk(2));
assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, User>>', $associativeCollection->chunk(2, false));

assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, User>>', $collection->chunkWhile(function ($user, $int, $collection) {
    assertType('User', $user);
    assertType('int', $int);
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return true;
}));

assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, User>>', $collection->chunkBy(fn ($user) => $user->getKey()));
assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, User>>', $collection->chunkBy('name'));

assertType('Hypervel\Support\Collection<int, User>', $collection->sort(function ($userA, $userB) {
    assertType('User', $userA);
    assertType('User', $userB);

    return 1;
}));
assertType('Hypervel\Support\Collection<int, User>', $collection->sort());

assertType('Hypervel\Support\Collection<int, User>', $collection->sortDesc());
assertType('Hypervel\Support\Collection<int, User>', $collection->sortDesc(2));

assertType('Hypervel\Support\Collection<int, User>', $collection->sortBy(function ($user, $int) {
    // assertType('User', $user);
    // assertType('int', $int);

    return 1;
}));
assertType('Hypervel\Support\Collection<int, User>', $collection->sortBy('string'));
assertType('Hypervel\Support\Collection<int, User>', $collection->sortBy('string', 1, false));
assertType('Hypervel\Support\Collection<int, User>', $collection->sortBy([
    ['string', 'asc'],
    ['foo', SortDirection::Descending],
]));
assertType('Hypervel\Support\Collection<int, User>', $collection->sortBy([function ($user, $int) {
    // assertType('User', $user);
    // assertType('int', $int);

    return 1;
}]));

assertType('Hypervel\Support\Collection<int, User>', $collection->sortByDesc(function ($user, $int) {
    // assertType('User', $user);
    // assertType('int', $int);

    return 1;
}));
assertType('Hypervel\Support\Collection<int, User>', $collection->sortByDesc('string'));
assertType('Hypervel\Support\Collection<int, User>', $collection->sortByDesc('string', 1));
assertType('Hypervel\Support\Collection<int, User>', $collection->sortByDesc([
    ['string', 'asc'],
    ['foo', SortDirection::Descending],
]));
assertType('Hypervel\Support\Collection<int, User>', $collection->sortByDesc([function ($user, $int) {
    // assertType('User', $user);
    // assertType('int', $int);

    return 1;
}]));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->sortKeys());
assertType('Hypervel\Support\Collection<string, string>', $collection::make(['string' => 'string'])->sortKeys(1, true));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->sortKeysDesc());
assertType('Hypervel\Support\Collection<string, string>', $collection::make(['string' => 'string'])->sortKeysDesc(1));

assertType('mixed', $collection::make([1])->sum('string'));
assertType('float|int', $collection::make(['string'])->sum(function ($string) {
    assertType('string', $string);

    return mt_rand(1, 2);
}));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->take(1));
assertType('Hypervel\Support\Collection<int, User>', $collection->take(1));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->takeUntil(1));
assertType('Hypervel\Support\Collection<int, User>', $collection->takeUntil(new User));
assertType('Hypervel\Support\Collection<int, User>', $collection->takeUntil(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->takeWhile(1));
assertType('Hypervel\Support\Collection<int, User>', $collection->takeWhile(new User));
assertType('Hypervel\Support\Collection<int, User>', $collection->takeWhile(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));

assertType('Hypervel\Support\Collection<int, User>', $collection->tap(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);
}));

assertType('Hypervel\Support\Collection<int, int>', $collection->pipe(function ($collection) {
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return collect([1]);
}));
assertType('1', $collection::make([1])->pipe(function ($collection) {
    assertType('Hypervel\Support\Collection<int, int>', $collection);

    return 1;
}));

assertType('User', $collection->pipeInto(User::class));

assertType('Hypervel\Support\Collection<(int|string), mixed>', $collection::make(['string' => 'string'])->pluck('string'));
assertType('Hypervel\Support\Collection<(int|string), mixed>', $collection::make(['string' => 'string'])->pluck('string', 'string'));

assertType('Hypervel\Support\Collection<int, User>', $collection->reject());
assertType('Hypervel\Support\Collection<int, User>', $collection->reject(new User));
assertType('Hypervel\Support\Collection<int, User>', $collection->reject(function ($user) {
    assertType('User', $user);

    return true;
}));
assertType('Hypervel\Support\Collection<int, User>', $collection->reject(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));

assertType('Hypervel\Support\Collection<int, User>', $collection->unique());
assertType('Hypervel\Support\Collection<int, User>', $collection->unique(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return $user->getTable();
}));
assertType('Hypervel\Support\Collection<string, string>', $collection::make(['string' => 'string'])->unique(function ($stringA, $stringB) {
    assertType('string', $stringA);
    assertType('string', $stringB);

    return $stringA;
}, true));

assertType('Hypervel\Support\Collection<int, User>', $collection->uniqueStrict());
assertType('Hypervel\Support\Collection<int, User>', $collection->uniqueStrict(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return $user->getTable();
}));

assertType('Hypervel\Support\Collection<int, User>', $collection->values());
assertType('Hypervel\Support\Collection<int, string>', $collection::make(['string', 'string'])->values());
assertType('Hypervel\Support\Collection<int, int|string>', $collection::make(['string', 1])->values());

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->pad(2, 0));
assertType('Hypervel\Support\Collection<int, int|string>', $collection::make([1])->pad(2, 'string'));
assertType('Hypervel\Support\Collection<int, int|User>', $collection->pad(2, 0));

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

assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, int|User>>', $collection->zip([1]));
assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, string|User>>', $collection->zip(['string']));
assertType('Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, string>>', $collection::make(['string' => 'string'])->zip(['string']));

assertType('Hypervel\Support\Collection<int, User>', $collection->collect());
assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->collect());

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->push(2));

assertType('array<int, User>', $collection->all());

assertType('User|null', $collection->get(0));
assertType("'string'|User", $collection->get(0, 'string'));
assertType("'string'|User", $collection->get(0, function () {
    return 'string';
}));

assertType("'string'|User", $collection->getOrPut(0, 'string'));
assertType("'string'|User", $collection->getOrPut(0, fn () => 'string'));

assertType('Hypervel\Support\Collection<int, User>', $collection->forget(1));
assertType('Hypervel\Support\Collection<int, User>', $collection->forget([1, 2]));

assertType('User|null', $collection->pop());
assertType('Hypervel\Support\Collection<int, User>', $collection->pop(2));

assertType('Hypervel\Support\Collection<int, string>', $collection::make([
    'string-key-1' => 'string-value-1',
    'string-key-2' => 'string-value-2',
])->pop(2));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->prepend(2));
assertType('Hypervel\Support\Collection<int, User>', $collection->prepend(new User, 2));

assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->push(2));
assertType('Hypervel\Support\Collection<int, User>', $collection->push(new User, new User));

assertType('User|null', $collection->pull(1));
assertType("'string'|User", $collection->pull(1, 'string'));
assertType("'string'|User", $collection->pull(1, function () {
    return 'string';
}));

assertType('Hypervel\Support\Collection<int, User>', $collection->put(1, new User));
assertType('Hypervel\Support\Collection<string, string>', $collection::make([
    'string-key-1' => 'string-value-1',
])->put('string-key-2', 'string-value-2'));

assertType('User|null', $collection->shift());
assertType('Hypervel\Support\Collection<int, string>', $collection::make([
    'string-key-1' => 'string-value-1',
    'string-key-2' => 'string-value-2',
])->shift(2));

assertType(
    'Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, User>>',
    $collection->sliding(2)
);

assertType(
    'Hypervel\Support\Collection<int, Hypervel\Support\Collection<string, string>>',
    $collection::make(['string' => 'string'])->sliding(2, 1)
);

assertType(
    'Hypervel\Support\Collection<int, Hypervel\Support\Collection<int, User>>',
    $collection->splitIn(2)
);

assertType(
    'Hypervel\Support\Collection<int, Hypervel\Support\Collection<string, string>>',
    $collection::make(['string' => 'string'])->splitIn(1)
);

assertType('Hypervel\Support\Collection<int, User>', $collection->splice(1));
assertType('Hypervel\Support\Collection<int, User>', $collection->splice(1, 1, [new User]));

assertType('Hypervel\Support\Collection<int, int>', $collection->transform(function ($user, $int): int {
    assertType('User', $user);
    assertType('int', $int);

    return $int * 2;
}));

assertType('Hypervel\Support\Collection<int, User>', $collection->transform(function ($value, $key) {
    assertType('int', $value);
    assertType('int', $key);

    return new User;
}));

assertType('Hypervel\Support\Collection<int, User>', $collection->add(new User));

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends Collection<TKey, TValue>
 */
class CustomCollection extends Collection
{
}

// assertType('CustomCollection<int, User>', CustomCollection::make([new User]));
assertType('Hypervel\Support\Collection<int, User>', CustomCollection::make([new User])->toBase());

assertType('bool', $collection->offsetExists(0));
assertType('bool', isset($collection[0]));

$collection->offsetSet(0, new User);
$collection->offsetSet(null, new User);
assertType('User', $collection[0] = new User);

$collection->offsetUnset(0);
unset($collection[0]);

assertType('array<int, mixed>', $collection->toArray());
assertType('array<string, mixed>', collect(['string' => 'string'])->toArray());
assertType('array<int, mixed>', collect([1, 2])->toArray());

assertType('ArrayIterator<int, User>', $collection->getIterator());
foreach ($collection as $int => $user) {
    assertType('int', $int);
    assertType('User', $user);
}

class Animal
{
}
class Tiger extends Animal
{
}
class Lion extends Animal
{
}
class Zebra extends Animal
{
}

class Zoo
{
    /**
     * @var Collection<int, Animal>
     */
    private Collection $animals;

    /**
     * Create a zoo with several animal types.
     */
    public function __construct()
    {
        $this->animals = collect([
            new Tiger,
            new Lion,
            new Zebra,
        ]);
    }

    /**
     * Get the animals other than zebras.
     *
     * @return Collection<int, Animal>
     */
    public function getWithoutZebras(): Collection
    {
        return $this->animals->filter(fn (Animal $animal) => ! $animal instanceof Zebra);
    }
}

$zoo = new Zoo;

assertType('Hypervel\Support\Collection<int, Animal>', $zoo->getWithoutZebras());

$coll = $zoo->getWithoutZebras();
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'average', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->average);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'avg', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->avg);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'contains', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->contains);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'doesntContain', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->doesntContain);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'each', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->each);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'every', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->every);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'filter', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->filter);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'first', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->first);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'flatMap', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->flatMap);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'groupBy', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->groupBy);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'keyBy', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->keyBy);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'last', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->last);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'map', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->map);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'max', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->max);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'min', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->min);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'partition', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->partition);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'percentage', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->percentage);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'reject', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->reject);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'skipUntil', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->skipUntil);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'skipWhile', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->skipWhile);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'some', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->some);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'sortBy', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->sortBy);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'sortByDesc', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->sortByDesc);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'sum', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->sum);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'takeUntil', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->takeUntil);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'takeWhile', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->takeWhile);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'unique', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->unique);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'unless', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->unless);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'until', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->until);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'when', Animal, Hypervel\\Support\\Collection<int, Animal>>", $coll->when);

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
