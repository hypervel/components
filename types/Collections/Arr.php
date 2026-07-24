<?php

declare(strict_types=1);

use ArrayObject;
use Hypervel\Support\Arr;
use stdClass;

use function PHPStan\Testing\assertType;

assertType('true', Arr::arrayable([]));
assertType('true', Arr::arrayable(new ArrayObject));
assertType('false', Arr::arrayable(1));

assertType("array{array<'a'|'b'>, array<1|2>}", Arr::divide(['a' => 1, 'b' => 2]));
assertType('array<int, array<1|2|3>>', Arr::crossJoin([1], [2], ['third' => 3]));

$array = ['first' => 1, 'second' => 2, 'third' => 3];

assertType('mixed', Arr::random($array));
assertType('array', Arr::random($array, 2));
assertType("array<'first'|'second'|'third', 1|2|3>", Arr::sort($array));
assertType("array<'first'|'second'|'third', 1|2|3>", Arr::sortDesc($array));
assertType("array<'first'|'second'|'third', 1|2|3>", Arr::where($array, static fn (int $value): bool => $value > 1));
assertType("array<'first'|'second'|'third', 1|2|3>", Arr::reject($array, static fn (int $value): bool => $value > 1));

/** @var array<string, null|int> $nullable */
$nullable = [];
assertType('array<string, int>', Arr::whereNotNull($nullable));

assertType('array{}', Arr::wrap(null));
assertType('array{1}', Arr::wrap(1));
assertType("array<'first'|'second'|'third', 1|2|3>", Arr::wrap($array));
assertType("''", Arr::toCssClasses([]));
assertType('non-empty-string', Arr::toCssClasses(['hidden' => true]));
assertType("''", Arr::toCssStyles([]));
assertType('non-empty-string', Arr::toCssStyles(['display: none' => true]));

/** @var iterable<string, int> $iterable */
$iterable = new ArrayObject($array);
assertType('int|null', Arr::last($iterable));
assertType('bool', Arr::every($iterable, static fn (int $value, string $key): bool => $value > 0 && $key !== ''));
assertType('bool', Arr::some($iterable, static fn (int $value, string $key): bool => $value > 0 && $key !== ''));

$target = [];
assertType('array', Arr::push($target, 'items', new stdClass));
