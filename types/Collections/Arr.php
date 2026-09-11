<?php

declare(strict_types=1);

use ArrayIterator;
use ArrayObject;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Support\Arr;
use JsonSerializable;
use stdClass;
use Traversable;

use function PHPStan\Testing\assertType;

$array = [new User];
/** @var iterable<int, User> $iterable */
$iterable = [];
/** @var Traversable<int, User> $traversable */
$traversable = new ArrayIterator([new User]);

assertType('User|null', Arr::first($array));
assertType('User|null', Arr::first($array, function ($user): bool {
    assertType('User', $user);

    return true;
}));
assertType("'string'|User", Arr::first($array, function ($user): bool {
    assertType('User', $user);

    return false;
}, 'string'));
assertType("'string'|User", Arr::first($array, null, function (): string {
    return 'string';
}));

assertType('User|null', Arr::first($iterable));
assertType('User|null', Arr::first($iterable, function ($user): bool {
    assertType('User', $user);

    return true;
}));
assertType("'string'|User", Arr::first($iterable, function ($user): bool {
    assertType('User', $user);

    return false;
}, 'string'));
assertType("'string'|User", Arr::first($iterable, null, function (): string {
    return 'string';
}));

assertType('User|null', Arr::first($traversable));
assertType('User|null', Arr::first($traversable, function ($user): bool {
    assertType('User', $user);

    return true;
}));
assertType("'string'|User", Arr::first($traversable, function ($user): bool {
    assertType('User', $user);

    return false;
}, 'string'));
assertType("'string'|User", Arr::first($traversable, null, function (): string {
    return 'string';
}));

assertType('User|null', Arr::last($array));
assertType('User|null', Arr::last($array, function ($user): bool {
    assertType('User', $user);

    return true;
}));
assertType("'string'|User", Arr::last($array, function ($user): bool {
    assertType('User', $user);

    return false;
}, 'string'));
assertType("'string'|User", Arr::last($array, null, function (): string {
    return 'string';
}));

assertType('User|null', Arr::last($iterable));
assertType('User|null', Arr::last($iterable, function ($user): bool {
    assertType('User', $user);

    return true;
}));
assertType("'string'|User", Arr::last($iterable, function ($user): bool {
    assertType('User', $user);

    return false;
}, 'string'));
assertType("'string'|User", Arr::last($iterable, null, function (): string {
    return 'string';
}));

assertType('User|null', Arr::last($traversable));
assertType('User|null', Arr::last($traversable, function ($user): bool {
    assertType('User', $user);

    return true;
}));
assertType("'string'|User", Arr::last($traversable, function ($user): bool {
    assertType('User', $user);

    return false;
}, 'string'));
assertType("'string'|User", Arr::last($traversable, null, function (): string {
    return 'string';
}));

assertType("array{array<'a'|'b'>, array<1|2>}", Arr::divide(['a' => 1, 'b' => 2]));
assertType('array{array<0>, array<1>}', Arr::divide([1]));

/**
 * Generate an iterable of integers.
 *
 * @return iterable<int>
 */
function generateArray(): iterable
{
    yield 1;
}

assertType('true', Arr::arrayable([]));
assertType('true', Arr::arrayable(new class implements Arrayable {
    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [];
    }
}));
assertType('true', Arr::arrayable(new class implements Jsonable {
    /**
     * Convert the object to its JSON representation.
     */
    public function toJson(int $options = 0): string
    {
        return '{"foo":"bar"}';
    }
}));
assertType('true', Arr::arrayable(generateArray()));
assertType('true', Arr::arrayable(new class implements JsonSerializable {
    /**
     * Return data for JSON serialization.
     */
    #[Override]
    public function jsonSerialize(): mixed
    {
        return '{"foo":"bar"}';
    }
}));
assertType('true', Arr::arrayable(new ArrayObject));
assertType('false', Arr::arrayable(1));

assertType('array<int, array<1|2|3>>', Arr::crossJoin([1], [2], ['a' => 3]));

/* @phpstan-ignore staticMethod.impossibleType */
assertType('false', Arr::isAssoc([1]));

/* @phpstan-ignore staticMethod.alreadyNarrowedType */
assertType('true', Arr::isAssoc(['a' => 1]));

/* @phpstan-ignore staticMethod.alreadyNarrowedType */
assertType('true', Arr::isList([1]));

/* @phpstan-ignore staticMethod.impossibleType */
assertType('false', Arr::isList(['a' => 1]));

assertType('array<0|1|2, 1|2|3>', Arr::sort([1, 3, 2]));
assertType("array<'a'|'b'|'c', 1|2|3>", Arr::sort(['a' => 1, 'c' => 3, 'b' => 2]));
assertType('array<0|1|2, 1|2|3>', Arr::sortDesc([1, 3, 2]));
assertType("array<'a'|'b'|'c', 1|2|3>", Arr::sortDesc(['a' => 1, 'c' => 3, 'b' => 2]));
assertType('array<0|1|2, 1|2|3>', Arr::sortRecursive([1, 3, 2]));
assertType("array<'a'|'b'|'c', 1|2|3>", Arr::sortRecursive(['a' => 1, 'c' => 3, 'b' => 2]));
assertType('array<0|1|2, 1|2|3>', Arr::sortRecursiveDesc([1, 3, 2]));
assertType("array<'a'|'b'|'c', 1|2|3>", Arr::sortRecursiveDesc(['a' => 1, 'c' => 3, 'b' => 2]));

// CSS entries do not guarantee a non-empty result, and empty styles gain a semicolon.
assertType('string', Arr::toCssClasses(['hidden' => false]));
assertType('string', Arr::toCssClasses([]));
assertType('string', Arr::toCssClasses(''));
assertType('string', Arr::toCssClasses(['hidden' => true]));
assertType('string', Arr::toCssClasses(['hidden']));
assertType('string', Arr::toCssClasses('hidden'));

assertType('string', Arr::toCssStyles(['background: red' => false]));
assertType('string', Arr::toCssStyles([]));
assertType('string', Arr::toCssStyles(''));
assertType('string', Arr::toCssStyles(['background: red' => true]));
assertType('string', Arr::toCssStyles(['background: red']));
assertType('string', Arr::toCssStyles('background: red'));

assertType('array{}', Arr::wrap(null));
assertType('array{}', Arr::wrap([]));
assertType('array{1}', Arr::wrap(1));
assertType("array{'hello'}", Arr::wrap('hello'));
assertType('array{stdClass}', Arr::wrap(new stdClass));
assertType('array<0, 1>', Arr::wrap([1]));
assertType("array<'a'|'b', 1|2>", Arr::wrap(['a' => 1, 'b' => 2]));
/** @var list<object>|object $value */
assertType('array<int<0, max>, object>', Arr::wrap($value));
/** @var null|float|float[] $value */
assertType('array<float>', Arr::wrap($value));
/** @var null|float|float[]|string|string[] $value */
assertType('array<float|string>', Arr::wrap($value));
/** @var null|array<string, float>|float $value */
assertType('array<0|string, float>', Arr::wrap($value));
/** @var array<float[]> $value */
assertType('array<array<float>>', Arr::wrap($value));
/** @var null|stdClass|stdClass[] $value */
assertType('array<stdClass>', Arr::wrap($value));

/** @var array<string, null|int> $arr */
assertType('array<string, int>', Arr::whereNotNull($arr));

/** @var list<null|int> $arr */
assertType('array<int<0, max>, int>', Arr::whereNotNull($arr));

assertType('mixed', Arr::random($array));
assertType('array', Arr::random($array, 2));

assertType('array<0|1, mixed>', Arr::mapSpread([[0, 1], [2, 3]], fn (int $even, int $odd): int => $even + $odd));

// Numeric prefixes can produce integer array keys.
assertType('array<User>', Arr::prependKeysWith($array, 'user_'));

$numbers = ['first' => 1, 'second' => 2, 'third' => 3];

assertType("array<'first'|'second'|'third', 1|2|3>", Arr::where($numbers, static fn (int $value): bool => $value > 1));
assertType("array<'first'|'second'|'third', 1|2|3>", Arr::reject($numbers, static fn (int $value): bool => $value > 1));

/** @var iterable<string, int> $iterable */
$iterable = new ArrayObject($numbers);
assertType('bool', Arr::every($iterable, static fn (int $value, string $key): bool => $value > 0 && $key !== ''));
assertType('bool', Arr::some($iterable, static fn (int $value, string $key): bool => $value > 0 && $key !== ''));

$users = [['name' => 'b'], ['name' => 'a']];
Arr::sort($users, ['name']);
Arr::sort($users, [['name', false]]);
Arr::sort([5, 1], [fn (int $a, int $b): int => $a - $b]);
Arr::sortDesc($users, [['name', true]]);

// A comparison list receives two values, while a top-level callback receives a value and key.
Arr::sort($users, fn (array $user, int $key): string => $user['name']);
Arr::sort($users, [fn (array $user, int $key): int => $key]); // @phpstan-ignore argument.type

$target = [];
assertType('array', Arr::push($target, 'items', new stdClass));
