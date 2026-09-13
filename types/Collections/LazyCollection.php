<?php

declare(strict_types=1);

use ArrayIterator;
use DateTimeImmutable;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use SortDirection;
use Traversable;

use function PHPStan\Testing\assertType;

/** @implements Arrayable<int, User> */
class LazyUsers implements Arrayable
{
    /**
     * Get the users as an array.
     */
    public function toArray(): array
    {
        return [new User];
    }
}

$collection = new LazyCollection([new User]);
$arrayable = new LazyUsers;
/** @var iterable<int, int> $iterable */
$iterable = [1];
/** @var Traversable<int, string> $traversable */
$traversable = new ArrayIterator(['string']);
$generator = function () {
    yield new User;
};

$associativeCollection = new LazyCollection(['Sam' => new User]);

assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

assertType("Hypervel\\Support\\LazyCollection<int, 'string'>", new LazyCollection(['string']));
assertType('Hypervel\Support\LazyCollection<string, User>', new LazyCollection(['string' => new User]));
assertType('Hypervel\Support\LazyCollection<int, User>', new LazyCollection($arrayable));
assertType('Hypervel\Support\LazyCollection<int, int>', new LazyCollection($iterable));
assertType('Hypervel\Support\LazyCollection<int, string>', new LazyCollection($traversable));
assertType('Hypervel\Support\LazyCollection<int, User>', new LazyCollection($generator));

assertType('Hypervel\Support\LazyCollection<int, string>', LazyCollection::make(['string']));
assertType('Hypervel\Support\LazyCollection<string, User>', LazyCollection::make(['string' => new User]));
assertType('Hypervel\Support\LazyCollection<int, User>', LazyCollection::make($arrayable));
assertType('Hypervel\Support\LazyCollection<int, int>', LazyCollection::make($iterable));
assertType('Hypervel\Support\LazyCollection<int, string>', LazyCollection::make($traversable));
assertType('Hypervel\Support\LazyCollection<int, User>', LazyCollection::make($generator));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection::times(10, function ($int) {
    // assertType('int', $int);

    return new User;
}));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection::times(10, function () {
    return new User;
}));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->each(function ($user) {
    assertType('User', $user);
}));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::range(1, 100));

assertType('Hypervel\Support\LazyCollection<(int|string), string>', $collection::wrap('string'));
assertType('Hypervel\Support\LazyCollection<(int|string), User>', $collection::wrap(new User));

assertType('Hypervel\Support\LazyCollection<(int|string), string>', $collection::wrap(['string']));
assertType('Hypervel\Support\LazyCollection<(int|string), User>', $collection::wrap(['string' => new User]));

assertType("array<0, 'string'>", $collection::unwrap(['string']));
assertType('array<int, User>', $collection::unwrap(
    $collection
));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection::empty());

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

assertType('Hypervel\Support\LazyCollection<int, mixed>', $collection->collapse());

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

assertType('Hypervel\Support\LazyCollection<int, array<int, string|User>>', $collection->crossJoin($collection::make(['string'])));
assertType('Hypervel\Support\LazyCollection<int, array<int, int|User>>', $collection->crossJoin([1, 2]));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([3, 4])->diff([1, 2]));
assertType('Hypervel\Support\LazyCollection<int, string>', $collection::make(['string-1'])->diff(['string-2']));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([3, 4])->diffUsing([1, 2], function ($intA, $intB) {
    assertType('int', $intA);
    assertType('int', $intB);

    return -1;
}));
assertType('Hypervel\Support\LazyCollection<int, string>', $collection::make(['string-1'])->diffUsing(['string-2'], function ($stringA, $stringB) {
    assertType('string', $stringA);
    assertType('string', $stringB);

    return -1;
}));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([3, 4])->diffAssoc([1, 2]));
assertType('Hypervel\Support\LazyCollection<string, string>', $collection::make(['string' => 'string'])->diffAssoc(['string' => 'string']));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([3, 4])->diffAssocUsing([1, 2], function ($intA, $intB) {
    assertType('int', $intA);
    assertType('int', $intB);

    return -1;
}));
assertType('Hypervel\Support\LazyCollection<int, string>', $collection::make(['string-1'])->diffAssocUsing(['string-2'], function ($intA, $intB) {
    assertType('int', $intA);
    assertType('int', $intB);

    return -1;
}));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([3, 4])->diffKeys([1, 2]));
assertType('Hypervel\Support\LazyCollection<string, string>', $collection::make(['string' => 'string'])->diffKeys(['string' => 'string']));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([3, 4])->diffKeysUsing([1, 2], function ($intA, $intB) {
    assertType('int', $intA);
    assertType('int', $intB);

    return -1;
}));
assertType('Hypervel\Support\LazyCollection<int, string>', $collection::make(['string-1'])->diffKeysUsing(['string-2'], function ($intA, $intB) {
    assertType('int', $intA);
    assertType('int', $intB);

    return -1;
}));

assertType('Hypervel\Support\LazyCollection<string, string>', $collection::make(['string' => 'string'])
    ->duplicates());
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->duplicates('name', true));
assertType('Hypervel\Support\LazyCollection<int, int|string>', $collection::make([3, 'string'])
    ->duplicates(function ($intOrString) {
        assertType('int|string', $intOrString);

        return true;
    }));

assertType('Hypervel\Support\LazyCollection<string, string>', $collection::make(['string' => 'string'])
    ->duplicatesStrict());
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->duplicatesStrict('name'));
assertType('Hypervel\Support\LazyCollection<int, int|string>', $collection::make([3, 'string'])
    ->duplicatesStrict(function ($intOrString) {
        assertType('int|string', $intOrString);

        return true;
    }));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->each(function ($user) {
    assertType('User', $user);

    return null;
}));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->each(function ($user) {
    assertType('User', $user);
}));

assertType('Hypervel\Support\LazyCollection<int, array{string}>', $collection::make([['string']])
    ->eachSpread(function ($int, $string) {
        // assertType('int', $int);
        // assertType('int', $string);

        return null;
    }));
assertType('Hypervel\Support\LazyCollection<int, array{int, string}>', $collection::make([[1, 'string']])
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

assertType('Hypervel\Support\LazyCollection<string, string>', $collection::make(['string' => 'string'])->except(['string']));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->except([1]));
assertType('Hypervel\Support\LazyCollection<int, string>', $collection::make(['string'])
    ->except([1]));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->filter());
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->filter(function ($user) {
    assertType('User', $user);

    return true;
}));

assertType('Hypervel\Support\LazyCollection<int, User>|true', $collection->when(true, function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

    return true;
}));
assertType('Hypervel\Support\LazyCollection<int, User>|null', $collection->when(true, function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);
}));
assertType("'string'|Hypervel\\Support\\LazyCollection<int, User>", $collection->when(true, function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

    return 'string';
}));

assertType('Hypervel\Support\LazyCollection<int, User>|true', $collection->whenEmpty(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

    return true;
}));
assertType('Hypervel\Support\LazyCollection<int, User>|null', $collection->whenEmpty(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);
}));
assertType("'string'|Hypervel\\Support\\LazyCollection<int, User>", $collection->whenEmpty(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

    return 'string';
}));

assertType('Hypervel\Support\LazyCollection<int, User>|true', $collection->whenNotEmpty(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

    return true;
}));
assertType('Hypervel\Support\LazyCollection<int, User>|null', $collection->whenNotEmpty(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);
}));
assertType("'string'|Hypervel\\Support\\LazyCollection<int, User>", $collection->whenNotEmpty(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

    return 'string';
}));

assertType('Hypervel\Support\LazyCollection<int, User>|true', $collection->unless(true, function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

    return true;
}));
assertType('Hypervel\Support\LazyCollection<int, User>|null', $collection->unless(true, function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);
}));
assertType("'string'|Hypervel\\Support\\LazyCollection<int, User>", $collection->unless(true, function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

    return 'string';
}));

assertType('Hypervel\Support\LazyCollection<int, User>|true', $collection->unlessEmpty(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

    return true;
}));
assertType('Hypervel\Support\LazyCollection<int, User>|null', $collection->unlessEmpty(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);
}));
assertType("'string'|Hypervel\\Support\\LazyCollection<int, User>", $collection->unlessEmpty(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

    return 'string';
}));

assertType('Hypervel\Support\LazyCollection<int, User>|true', $collection->unlessNotEmpty(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

    return true;
}));
assertType('Hypervel\Support\LazyCollection<int, User>|null', $collection->unlessNotEmpty(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);
}));
assertType("'string'|Hypervel\\Support\\LazyCollection<int, User>", $collection->unlessNotEmpty(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

    return 'string';
}));

assertType('Hypervel\Support\LazyCollection<int, array{string: string}>', $collection::make([['string' => 'string']])
    ->where('string'));
assertType('Hypervel\Support\LazyCollection<int, array{string: string}>', $collection::make([['string' => 'string']])
    ->where('string', '=', 'string'));
assertType('Hypervel\Support\LazyCollection<int, array{string: string}>', $collection::make([['string' => 'string']])
    ->where('string', 'string'));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->whereNull());
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->whereNull('foo'));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->whereNotNull());
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->whereNotNull('foo'));

assertType('Hypervel\Support\LazyCollection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereStrict('string', 2));

assertType('Hypervel\Support\LazyCollection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereIn('string', [2]));

assertType('Hypervel\Support\LazyCollection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereInStrict('string', [2]));

assertType('Hypervel\Support\LazyCollection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereBetween('string', [1, 3]));

assertType('Hypervel\Support\LazyCollection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereNotBetween('string', [1, 3]));

assertType('Hypervel\Support\LazyCollection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereNotIn('string', [2]));

assertType('Hypervel\Support\LazyCollection<int, array{string: int}>', $collection::make([['string' => 2]])
    ->whereNotInStrict('string', [2]));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection::make([new User, 1])
    ->whereInstanceOf(User::class));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection::make([new User, 1])
    ->whereInstanceOf([User::class, User::class]));

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

assertType('User|null', $collection->last());
assertType('User|null', $collection->last(function ($user) {
    assertType('User', $user);

    return true;
}));
assertType("'string'|User", $collection->last(function ($user) {
    assertType('User', $user);

    return false;
}, 'string'));
assertType("'string'|User", $collection->last(null, function () {
    return 'string';
}));

assertType('Hypervel\Support\LazyCollection<int, mixed>', $collection->flatten());
assertType('Hypervel\Support\LazyCollection<int, mixed>', $collection::make(['string' => 'string'])->flatten(4));

assertType('User|null', $collection->firstWhere('string', 'string'));
assertType('User|null', $collection->firstWhere('string', 'string', 'string'));

assertType('Hypervel\Support\LazyCollection<string, int>', $collection::make(['string'])->flip());

assertType('Hypervel\Support\LazyCollection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy('name'));
assertType('Hypervel\Support\LazyCollection<(int|string), Hypervel\Support\Collection<int, User>>', $collection->groupBy('name', true));
assertType('Hypervel\Support\LazyCollection<(int|string), Hypervel\Support\Collection<(int|string), mixed>>', $collection->groupBy(['name', 'email']));
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

assertType('bool', $collection->has(0));
assertType('bool', $collection->has([0, 1]));

assertType('string', $collection->implode(function ($user, $index) {
    assertType('User', $user);
    assertType('int', $index);

    return 'string';
}));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->intersect([new User]));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->intersectByKeys([new User]));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection->keys());

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

assertType('Hypervel\Support\LazyCollection<int, int>', $collection->map(function () {
    return 1;
}));
assertType('Hypervel\Support\LazyCollection<int, string>', $collection->map(function () {
    return 'string';
}));

assertType('Hypervel\Support\LazyCollection<int, string>', $collection::make(['string'])
    ->map(function ($string, $int) {
        assertType('string', $string);
        assertType('int', $int);

        return (string) $string;
    }));

assertType('Hypervel\Support\LazyCollection<int, mixed>', $collection::make(['string'])
    ->mapSpread(function () {
        return 'string';
    }));

assertType('Hypervel\Support\LazyCollection<int, mixed>', $collection::make(['string'])
    ->mapSpread(function () {
        return 1;
    }));

assertType('Hypervel\Support\LazyCollection<int, mixed>', LazyCollection::make([[0, 1], [2, 3]])
    ->mapSpread(fn (int $even, int $odd): int => $even + $odd));

assertType('Hypervel\Support\LazyCollection<string, array<int, int>>', $collection::make(['string', 'string'])
    ->mapToDictionary(function ($stringValue, $stringKey) {
        assertType('string', $stringValue);
        assertType('int', $stringKey);

        return ['string' => 1];
    }));

assertType('Hypervel\Support\LazyCollection<string, Hypervel\Support\LazyCollection<int, int>>', $collection::make(['string', 'string'])
    ->mapToGroups(function ($stringValue, $stringKey) {
        assertType('string', $stringValue);
        assertType('int', $stringKey);

        return ['string' => 1];
    }));

assertType('Hypervel\Support\LazyCollection<string, int>', $collection::make(['string'])
    ->mapWithKeys(function ($string, $int) {
        assertType('string', $string);
        assertType('int', $int);

        return ['string' => 1];
    }));

assertType('Hypervel\Support\LazyCollection<int, string>', $collection::make(['string'])
    ->flatMap(function ($string, $int) {
        assertType('string', $string);
        assertType('int', $int);

        return [0 => 'string'];
    }));

assertType('Hypervel\Support\LazyCollection<int, string>', $collection::make(['string'])
    ->flatMap(fn ($string) => new LazyCollection([$string])));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->mapInto(User::class));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->merge([2]));
assertType('Hypervel\Support\LazyCollection<int, string>', $collection::make(['string'])->merge(['string']));

assertType('Hypervel\Support\LazyCollection<int, int|string>', $collection::make([1])->merge(['string']));
assertType('Hypervel\Support\LazyCollection<int, int|string>', $collection::make(['string'])->merge([1]));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->mergeRecursive([2]));
assertType('Hypervel\Support\LazyCollection<int, string>', $collection::make(['string'])->mergeRecursive(['string']));

assertType('Hypervel\Support\LazyCollection<string, int>', $collection::make(['string' => 'string'])->combine([2]));
assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->combine([1]));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->union([1]));
assertType('Hypervel\Support\LazyCollection<string, string>', $collection::make(['string' => 'string'])->union(['string' => 'string']));

assertType('null', $collection::make()->min());
assertType('int|null', $collection::make([1])->min());
assertType('mixed', $collection::make([1])->min('string'));
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

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->nth(1, 2));

assertType('Hypervel\Support\LazyCollection<string, string>', $collection::make(['string' => 'string'])->only(['string']));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->only([1]));
assertType('Hypervel\Support\LazyCollection<int, string>', $collection::make(['string'])
    ->only([1]));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->forPage(1, 2));

assertType('Hypervel\Support\LazyCollection<int<0, 1>, Hypervel\Support\LazyCollection<int, User>>', $collection->partition(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));
assertType('Hypervel\Support\LazyCollection<int<0, 1>, Hypervel\Support\LazyCollection<int, string>>', $collection::make(['string'])->partition('string', '=', 'string'));
assertType('Hypervel\Support\LazyCollection<int<0, 1>, Hypervel\Support\LazyCollection<int, string>>', $collection::make(['string'])->partition('string', 'string'));
assertType('Hypervel\Support\LazyCollection<int<0, 1>, Hypervel\Support\LazyCollection<int, string>>', $collection::make(['string'])->partition('string'));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->concat([2]));
assertType('Hypervel\Support\LazyCollection<int, string>', $collection::make(['string'])->concat(['string']));
assertType('Hypervel\Support\LazyCollection<int, int|string>', $collection::make([1])->concat(['string']));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->random(2));
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

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->replace([1]));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->replace([new User]));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->replaceRecursive([1]));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->replaceRecursive([new User]));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->reverse());

// assertType('int|bool', $collection::make([1])->search(2));
// assertType('string|bool', $collection::make(['string' => 'string'])->search('string'));
// assertType('int|bool', $collection->search(function ($user, $int) {
//     assertType('User', $user);
//     assertType('int', $int);

//    return true;
// }));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->shuffle());
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->shuffle());

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->skip(1));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->skip(1));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->skipUntil(1));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->skipUntil(new User));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->skipUntil(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->skipWhile(1));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->skipWhile(new User));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->skipWhile(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->slice(1));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->slice(1, 2));

assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<int, User>>', $collection->split(3));
assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<int, int>>', $collection::make([1])->split(3));

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

assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<int, string>>', $collection::make(['string'])->chunk(1));
assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<int, User>>', $collection->chunk(2));
assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<string, User>>', $associativeCollection->chunk(2));
assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<int, User>>', $associativeCollection->chunk(2, false));

assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<int, User>>', $collection->chunkWhile(function ($user, $int, $collection) {
    assertType('User', $user);
    assertType('int', $int);
    assertType('Hypervel\Support\Collection<int, User>', $collection);

    return true;
}));

assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<int, User>>', $collection->chunkBy(fn ($user) => $user->getKey()));
assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<int, User>>', $collection->chunkBy('name'));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sort(function ($userA, $userB) {
    assertType('User', $userA);
    assertType('User', $userB);

    return 1;
}));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sort());

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sortDesc());
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sortDesc(2));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sortBy(function ($user, $int) {
    // assertType('User', $user);
    // assertType('int', $int);

    return 1;
}));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sortBy('string'));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sortBy('string', 1, false));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sortBy([
    ['string', 'asc'],
    ['foo', SortDirection::Descending],
]));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sortBy([function ($user, $int) {
    // assertType('User', $user);
    // assertType('int', $int);

    return 1;
}]));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sortByDesc(function ($user, $int) {
    // assertType('User', $user);
    // assertType('int', $int);

    return 1;
}));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sortByDesc('string'));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sortByDesc('string', 1));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sortByDesc([
    ['string', 'asc'],
    ['foo', SortDirection::Descending],
]));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->sortByDesc([function ($user, $int) {
    // assertType('User', $user);
    // assertType('int', $int);

    return 1;
}]));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->sortKeys());
assertType('Hypervel\Support\LazyCollection<string, string>', $collection::make(['string' => 'string'])->sortKeys(1, true));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->sortKeysDesc());
assertType('Hypervel\Support\LazyCollection<string, string>', $collection::make(['string' => 'string'])->sortKeysDesc(1));

assertType('mixed', $collection::make([1])->sum('string'));
assertType('float|int', $collection::make(['string'])->sum(function ($string) {
    assertType('string', $string);

    return mt_rand(1, 2);
}));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->take(1));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->take(1));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->takeUntil(1));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->takeUntil(new User));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->takeUntil(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->takeUntilTimeout(new DateTimeImmutable));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->takeUntilTimeout(new DateTimeImmutable, function ($user, $int) {
    assertType('User|null', $user);
    // assertType('int|null', $int);
}));

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->takeWhile(1));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->takeWhile(new User));
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->takeWhile(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return true;
}));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->tap(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);
}));

assertType('Hypervel\Support\LazyCollection<int, 1>', $collection->pipe(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, User>', $collection);

    return new LazyCollection([1]);
}));
assertType('1', $collection::make([1])->pipe(function ($collection) {
    assertType('Hypervel\Support\LazyCollection<int, int>', $collection);

    return 1;
}));

assertType('User', $collection->pipeInto(User::class));

assertType('Hypervel\Support\LazyCollection<(int|string), mixed>', $collection::make(['string' => 'string'])->pluck('string'));
assertType('Hypervel\Support\LazyCollection<(int|string), mixed>', $collection::make(['string' => 'string'])->pluck('string', 'string'));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->reject());
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->reject(function ($user) {
    assertType('User', $user);

    return true;
}));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->tapEach(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return null;
}));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->unique());
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->unique(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return $user->getTable();
}));
assertType('Hypervel\Support\LazyCollection<string, string>', $collection::make(['string' => 'string'])->unique(function ($stringA, $stringB) {
    assertType('string', $stringA);
    assertType('string', $stringB);

    return $stringA;
}, true));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->uniqueStrict());
assertType('Hypervel\Support\LazyCollection<int, User>', $collection->uniqueStrict(function ($user, $int) {
    assertType('User', $user);
    assertType('int', $int);

    return $user->getTable();
}));

assertType('Hypervel\Support\LazyCollection<int, User>', $collection->values());
assertType('Hypervel\Support\LazyCollection<int, string>', $collection::make(['string', 'string'])->values());
assertType('Hypervel\Support\LazyCollection<int, int|string>', $collection::make(['string', 1])->values());

assertType('Hypervel\Support\LazyCollection<int, int>', $collection::make([1])->pad(2, 0));
assertType('Hypervel\Support\LazyCollection<int, int|string>', $collection::make([1])->pad(2, 'string'));
assertType('Hypervel\Support\LazyCollection<int, int|User>', $collection->pad(2, 0));
assertType('Hypervel\Support\LazyCollection<int|string, int|User>', $associativeCollection->pad(2, 0));

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

assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<int, int|User>>', $collection->zip([1]));
assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<int, string|User>>', $collection->zip(['string']));
assertType('Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<int, string>>', $collection::make(['string' => 'string'])->zip(['string']));

assertType('Hypervel\Support\Collection<int, User>', $collection->collect());
assertType('Hypervel\Support\Collection<int, int>', $collection::make([1])->collect());

assertType('array<int, User>', $collection->all());

assertType('User|null', $collection->get(0));
assertType("'string'|User", $collection->get(0, 'string'));
assertType("'string'|User", $collection->get(0, function () {
    return 'string';
}));

assertType(
    'Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<int, User>>',
    $collection->sliding(2)
);

assertType(
    'Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<string, string>>',
    $collection::make(['string' => 'string'])->sliding(2, 1)
);

assertType(
    'Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<int, User>>',
    $collection->splitIn(2)
);

assertType(
    'Hypervel\Support\LazyCollection<int, Hypervel\Support\LazyCollection<string, string>>',
    $collection::make(['string' => 'string'])->splitIn(1)
);

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends LazyCollection<TKey, TValue>
 */
class CustomLazyCollection extends LazyCollection
{
}

// assertType('CustomLazyCollection<int, User>', CustomLazyCollection::make([new User]));

assertType('array<int, mixed>', $collection->toArray());
assertType('array<string, mixed>', LazyCollection::make(['string' => 'string'])->toArray());
assertType('array<int, mixed>', LazyCollection::make([1, 2])->toArray());

assertType('Iterator<int, User>', $collection->getIterator());
foreach ($collection as $int => $user) {
    assertType('int', $int);
    assertType('User', $user);
}

class LazyAnimal
{
}
class LazyTiger extends LazyAnimal
{
}
class LazyLion extends LazyAnimal
{
}
class LazyZebra extends LazyAnimal
{
}

class LazyZoo
{
    /**
     * @var Collection<int, LazyAnimal>
     */
    private Collection $animals;

    /**
     * Create a zoo with several animal types.
     */
    public function __construct()
    {
        $this->animals = collect([
            new LazyTiger,
            new LazyLion,
            new LazyZebra,
        ]);
    }

    /**
     * Get the animals other than zebras.
     *
     * @return LazyCollection<int, LazyAnimal>
     */
    public function getWithoutZebras(): LazyCollection
    {
        return $this->animals->lazy()->filter(fn (LazyAnimal $animal) => ! $animal instanceof LazyZebra);
    }
}

$zoo = new LazyZoo;

$coll = $zoo->getWithoutZebras();

assertType('Hypervel\Support\LazyCollection<int, LazyAnimal>', $coll);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'average', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->average);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'avg', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->avg);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'contains', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->contains);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'doesntContain', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->doesntContain);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'each', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->each);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'every', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->every);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'filter', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->filter);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'first', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->first);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'flatMap', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->flatMap);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'groupBy', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->groupBy);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'keyBy', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->keyBy);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'last', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->last);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'map', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->map);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'max', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->max);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'min', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->min);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'partition', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->partition);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'percentage', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->percentage);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'reject', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->reject);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'skipUntil', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->skipUntil);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'skipWhile', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->skipWhile);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'some', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->some);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'sortBy', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->sortBy);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'sortByDesc', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->sortByDesc);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'sum', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->sum);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'takeUntil', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->takeUntil);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'takeWhile', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->takeWhile);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'unique', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->unique);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'unless', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->unless);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'until', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->until);
assertType("Hypervel\\Support\\HigherOrderCollectionProxy<'when', LazyAnimal, Hypervel\\Support\\LazyCollection<int, LazyAnimal>>", $coll->when);
