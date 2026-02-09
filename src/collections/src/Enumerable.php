<?php

declare(strict_types=1);

namespace Hypervel\Support;

use CachingIterator;
use Closure;
use Countable;
use Exception;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use Traversable;
use UnexpectedValueException;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @extends Arrayable<TKey, TValue>
 * @extends IteratorAggregate<TKey, TValue>
 */
interface Enumerable extends Arrayable, Countable, IteratorAggregate, Jsonable, JsonSerializable
{
    /**
     * Create a new collection instance if the value isn't one already.
     *
     * @template TMakeKey of array-key
     * @template TMakeValue
     *
     * @param null|Arrayable<TMakeKey, TMakeValue>|iterable<TMakeKey, TMakeValue> $items
     * @return static<TMakeKey, TMakeValue>
     */
    public static function make(Arrayable|iterable|null $items = []): static;

    /**
     * Create a new instance by invoking the callback a given amount of times.
     */
    public static function times(int $number, ?callable $callback = null): static;

    /**
     * Create a collection with the given range.
     */
    public static function range(int $from, int $to, int $step = 1): static;

    /**
     * Wrap the given value in a collection if applicable.
     *
     * @template TWrapValue
     *
     * @param iterable<array-key, TWrapValue>|TWrapValue $value
     * @return static<array-key, TWrapValue>
     */
    public static function wrap(mixed $value): static;

    /**
     * Get the underlying items from the given collection if applicable.
     *
     * @template TUnwrapKey of array-key
     * @template TUnwrapValue
     *
     * @param array<TUnwrapKey, TUnwrapValue>|static<TUnwrapKey, TUnwrapValue> $value
     * @return array<TUnwrapKey, TUnwrapValue>
     */
    public static function unwrap(array|Enumerable $value): array;

    /**
     * Create a new instance with no items.
     */
    public static function empty(): static;

    /**
     * Get all items in the enumerable.
     */
    public function all(): array;

    /**
     * Alias for the "avg" method.
     *
     * @param null|(callable(TValue): (float|int))|string $callback
     */
    public function average(callable|string|null $callback = null): float|int|null;

    /**
     * Get the median of a given key.
     *
     * @param null|array<array-key, string>|string $key
     */
    public function median(string|array|null $key = null): float|int|null;

    /**
     * Get the mode of a given key.
     *
     * @param null|array<array-key, string>|string $key
     * @return null|array<int, float|int>
     */
    public function mode(string|array|null $key = null): ?array;

    /**
     * Collapse the items into a single enumerable.
     *
     * @return static<int, mixed>
     */
    public function collapse();

    /**
     * Alias for the "contains" method.
     *
     * @param (callable(TValue, TKey): bool)|string|TValue $key
     */
    public function some(mixed $key, mixed $operator = null, mixed $value = null): bool;

    /**
     * Determine if an item exists, using strict comparison.
     *
     * @param array-key|(callable(TValue): bool)|TValue $key
     * @param null|TValue $value
     */
    public function containsStrict(mixed $key, mixed $value = null): bool;

    /**
     * Get the average value of a given key.
     *
     * @param null|(callable(TValue): (float|int))|string $callback
     */
    public function avg(callable|string|null $callback = null): float|int|null;

    /**
     * Determine if an item exists in the enumerable.
     *
     * @param (callable(TValue, TKey): bool)|string|TValue $key
     */
    public function contains(mixed $key, mixed $operator = null, mixed $value = null): bool;

    /**
     * Determine if an item is not contained in the collection.
     */
    public function doesntContain(mixed $key, mixed $operator = null, mixed $value = null): bool;

    /**
     * Cross join with the given lists, returning all possible permutations.
     *
     * @template TCrossJoinKey of array-key
     * @template TCrossJoinValue
     *
     * @param Arrayable<TCrossJoinKey, TCrossJoinValue>|iterable<TCrossJoinKey, TCrossJoinValue> ...$lists
     * @return static<int, array<int, TCrossJoinValue|TValue>>
     */
    public function crossJoin(Arrayable|iterable ...$lists): static;

    /**
     * Dump the collection and end the script.
     */
    public function dd(mixed ...$args): never;

    /**
     * Dump the collection.
     */
    public function dump(mixed ...$args): static;

    /**
     * Get the items that are not present in the given items.
     *
     * @param Arrayable<array-key, TValue>|iterable<array-key, TValue> $items
     */
    public function diff(Arrayable|iterable $items): static;

    /**
     * Get the items that are not present in the given items, using the callback.
     *
     * @param Arrayable<array-key, TValue>|iterable<array-key, TValue> $items
     * @param callable(TValue, TValue): int $callback
     */
    public function diffUsing(Arrayable|iterable $items, callable $callback): static;

    /**
     * Get the items whose keys and values are not present in the given items.
     *
     * @param Arrayable<TKey, TValue>|iterable<TKey, TValue> $items
     */
    public function diffAssoc(Arrayable|iterable $items): static;

    /**
     * Get the items whose keys and values are not present in the given items, using the callback.
     *
     * @param Arrayable<TKey, TValue>|iterable<TKey, TValue> $items
     * @param callable(TKey, TKey): int $callback
     */
    public function diffAssocUsing(Arrayable|iterable $items, callable $callback): static;

    /**
     * Get the items whose keys are not present in the given items.
     *
     * @param Arrayable<TKey, mixed>|iterable<TKey, mixed> $items
     */
    public function diffKeys(Arrayable|iterable $items): static;

    /**
     * Get the items whose keys are not present in the given items, using the callback.
     *
     * @param Arrayable<TKey, mixed>|iterable<TKey, mixed> $items
     * @param callable(TKey, TKey): int $callback
     */
    public function diffKeysUsing(Arrayable|iterable $items, callable $callback): static;

    /**
     * Retrieve duplicate items.
     *
     * @param null|(callable(TValue): bool)|string $callback
     */
    public function duplicates(callable|string|null $callback = null, bool $strict = false): static;

    /**
     * Retrieve duplicate items using strict comparison.
     *
     * @param null|(callable(TValue): bool)|string $callback
     */
    public function duplicatesStrict(callable|string|null $callback = null): static;

    /**
     * Execute a callback over each item.
     *
     * @param callable(TValue, TKey): mixed $callback
     */
    public function each(callable $callback): static;

    /**
     * Execute a callback over each nested chunk of items.
     */
    public function eachSpread(callable $callback): static;

    /**
     * Determine if all items pass the given truth test.
     *
     * @param (callable(TValue, TKey): bool)|string|TValue $key
     */
    public function every(mixed $key, mixed $operator = null, mixed $value = null): bool;

    /**
     * Get all items except for those with the specified keys.
     *
     * @param array<array-key, TKey>|Enumerable<array-key, TKey> $keys
     */
    public function except(Enumerable|array $keys): static;

    /**
     * Run a filter over each of the items.
     *
     * @param null|(callable(TValue): bool) $callback
     */
    public function filter(?callable $callback = null): static;

    /**
     * Apply the callback if the given "value" is (or resolves to) truthy.
     *
     * @template TWhenReturnType as null
     *
     * @param null|(callable($this): TWhenReturnType) $callback
     * @param null|(callable($this): TWhenReturnType) $default
     * @return $this|TWhenReturnType
     */
    public function when(mixed $value, ?callable $callback = null, ?callable $default = null): mixed;

    /**
     * Apply the callback if the collection is empty.
     *
     * @template TWhenEmptyReturnType
     *
     * @param (callable($this): TWhenEmptyReturnType) $callback
     * @param null|(callable($this): TWhenEmptyReturnType) $default
     * @return $this|TWhenEmptyReturnType
     */
    public function whenEmpty(callable $callback, ?callable $default = null): mixed;

    /**
     * Apply the callback if the collection is not empty.
     *
     * @template TWhenNotEmptyReturnType
     *
     * @param callable($this): TWhenNotEmptyReturnType $callback
     * @param null|(callable($this): TWhenNotEmptyReturnType) $default
     * @return $this|TWhenNotEmptyReturnType
     */
    public function whenNotEmpty(callable $callback, ?callable $default = null): mixed;

    /**
     * Apply the callback if the given "value" is (or resolves to) falsy.
     *
     * @template TUnlessReturnType
     *
     * @param (callable($this): TUnlessReturnType) $callback
     * @param null|(callable($this): TUnlessReturnType) $default
     * @return $this|TUnlessReturnType
     */
    public function unless(mixed $value, callable $callback, ?callable $default = null): mixed;

    /**
     * Apply the callback unless the collection is empty.
     *
     * @template TUnlessEmptyReturnType
     *
     * @param callable($this): TUnlessEmptyReturnType $callback
     * @param null|(callable($this): TUnlessEmptyReturnType) $default
     * @return $this|TUnlessEmptyReturnType
     */
    public function unlessEmpty(callable $callback, ?callable $default = null): mixed;

    /**
     * Apply the callback unless the collection is not empty.
     *
     * @template TUnlessNotEmptyReturnType
     *
     * @param callable($this): TUnlessNotEmptyReturnType $callback
     * @param null|(callable($this): TUnlessNotEmptyReturnType) $default
     * @return $this|TUnlessNotEmptyReturnType
     */
    public function unlessNotEmpty(callable $callback, ?callable $default = null): mixed;

    /**
     * Filter items by the given key value pair.
     */
    public function where(callable|string|null $key, mixed $operator = null, mixed $value = null): static;

    /**
     * Filter items where the value for the given key is null.
     */
    public function whereNull(?string $key = null): static;

    /**
     * Filter items where the value for the given key is not null.
     */
    public function whereNotNull(?string $key = null): static;

    /**
     * Filter items by the given key value pair using strict comparison.
     */
    public function whereStrict(string $key, mixed $value): static;

    /**
     * Filter items by the given key value pair.
     */
    public function whereIn(string $key, Arrayable|iterable $values, bool $strict = false): static;

    /**
     * Filter items by the given key value pair using strict comparison.
     */
    public function whereInStrict(string $key, Arrayable|iterable $values): static;

    /**
     * Filter items such that the value of the given key is between the given values.
     */
    public function whereBetween(string $key, Arrayable|iterable $values): static;

    /**
     * Filter items such that the value of the given key is not between the given values.
     */
    public function whereNotBetween(string $key, Arrayable|iterable $values): static;

    /**
     * Filter items by the given key value pair.
     */
    public function whereNotIn(string $key, Arrayable|iterable $values, bool $strict = false): static;

    /**
     * Filter items by the given key value pair using strict comparison.
     */
    public function whereNotInStrict(string $key, Arrayable|iterable $values): static;

    /**
     * Filter the items, removing any items that don't match the given type(s).
     *
     * @template TWhereInstanceOf
     *
     * @param array<array-key, class-string<TWhereInstanceOf>>|class-string<TWhereInstanceOf> $type
     * @return static<TKey, TWhereInstanceOf>
     */
    public function whereInstanceOf(string|array $type): static;

    /**
     * Get the first item from the enumerable passing the given truth test.
     *
     * @template TFirstDefault
     *
     * @param null|(callable(TValue,TKey): bool) $callback
     * @param (Closure(): TFirstDefault)|TFirstDefault $default
     * @return TFirstDefault|TValue
     */
    public function first(?callable $callback = null, mixed $default = null): mixed;

    /**
     * Get the first item by the given key value pair.
     *
     * @return null|TValue
     */
    public function firstWhere(string $key, mixed $operator = null, mixed $value = null): mixed;

    /**
     * Get a flattened array of the items in the collection.
     */
    public function flatten(int|float $depth = INF);

    /**
     * Flip the values with their keys.
     *
     * @return static<TValue, TKey>
     * @phpstan-ignore generics.notSubtype (TValue becomes key - only valid when TValue is array-key, but can't express this constraint)
     */
    public function flip();

    /**
     * Get an item from the collection by key.
     *
     * @template TGetDefault
     *
     * @param TKey $key
     * @param (Closure(): TGetDefault)|TGetDefault $default
     * @return TGetDefault|TValue
     */
    public function get(mixed $key, mixed $default = null): mixed;

    /**
     * Group an associative array by a field or using a callback.
     *
     * @template TGroupKey of array-key
     *
     * @param array|(callable(TValue, TKey): TGroupKey)|string $groupBy
     * @return static<($groupBy is string ? array-key : ($groupBy is array ? array-key : TGroupKey)), static<($preserveKeys is true ? TKey : int), ($groupBy is array ? mixed : TValue)>>
     */
    public function groupBy(callable|array|string $groupBy, bool $preserveKeys = false): static;

    /**
     * Key an associative array by a field or using a callback.
     *
     * @template TNewKey of array-key
     *
     * @param array|(callable(TValue, TKey): TNewKey)|string $keyBy
     * @return static<($keyBy is string ? array-key : ($keyBy is array ? array-key : TNewKey)), TValue>
     */
    public function keyBy(callable|array|string $keyBy): static;

    /**
     * Determine if an item exists in the collection by key.
     *
     * @param array<array-key, TKey>|TKey $key
     */
    public function has(mixed $key): bool;

    /**
     * Determine if any of the keys exist in the collection.
     */
    public function hasAny(mixed $key): bool;

    /**
     * Concatenate values of a given key as a string.
     *
     * @param (callable(TValue, TKey): mixed)|string $value
     */
    public function implode(callable|string $value, ?string $glue = null): string;

    /**
     * Intersect the collection with the given items.
     *
     * @param Arrayable<TKey, TValue>|iterable<TKey, TValue> $items
     */
    public function intersect(Arrayable|iterable $items): static;

    /**
     * Intersect the collection with the given items, using the callback.
     *
     * @param Arrayable<array-key, TValue>|iterable<array-key, TValue> $items
     * @param callable(TValue, TValue): int $callback
     */
    public function intersectUsing(Arrayable|iterable $items, callable $callback): static;

    /**
     * Intersect the collection with the given items with additional index check.
     *
     * @param Arrayable<TKey, TValue>|iterable<TKey, TValue> $items
     */
    public function intersectAssoc(Arrayable|iterable $items): static;

    /**
     * Intersect the collection with the given items with additional index check, using the callback.
     *
     * @param Arrayable<array-key, TValue>|iterable<array-key, TValue> $items
     * @param callable(TValue, TValue): int $callback
     */
    public function intersectAssocUsing(Arrayable|iterable $items, callable $callback): static;

    /**
     * Intersect the collection with the given items by key.
     *
     * @param Arrayable<TKey, mixed>|iterable<TKey, mixed> $items
     */
    public function intersectByKeys(Arrayable|iterable $items): static;

    /**
     * Determine if the collection is empty or not.
     */
    public function isEmpty(): bool;

    /**
     * Determine if the collection is not empty.
     */
    public function isNotEmpty(): bool;

    /**
     * Determine if the collection contains a single item.
     */
    public function containsOneItem(): bool;

    /**
     * Determine if the collection contains multiple items.
     */
    public function containsManyItems(): bool;

    /**
     * Determine if the collection contains a single item, optionally matching the given criteria.
     *
     * @param null|(callable(TValue, TKey): bool)|string $key
     */
    public function hasSole(callable|string|null $key = null, mixed $operator = null, mixed $value = null): bool;

    /**
     * Determine if the collection contains multiple items, optionally matching the given criteria.
     *
     * @param null|(callable(TValue, TKey): bool)|string $key
     */
    public function hasMany(callable|string|null $key = null, mixed $operator = null, mixed $value = null): bool;

    /**
     * Join all items from the collection using a string. The final items can use a separate glue string.
     */
    public function join(string $glue, string $finalGlue = ''): string;

    /**
     * Get the keys of the collection items.
     *
     * @return static<int, TKey>
     */
    public function keys();

    /**
     * Get the last item from the collection.
     *
     * @template TLastDefault
     *
     * @param null|(callable(TValue, TKey): bool) $callback
     * @param (Closure(): TLastDefault)|TLastDefault $default
     * @return TLastDefault|TValue
     */
    public function last(?callable $callback = null, mixed $default = null): mixed;

    /**
     * Run a map over each of the items.
     *
     * @template TMapValue
     *
     * @param callable(TValue, TKey): TMapValue $callback
     * @return static<TKey, TMapValue>
     */
    public function map(callable $callback);

    /**
     * Run a map over each nested chunk of items.
     */
    public function mapSpread(callable $callback): static;

    /**
     * Run a dictionary map over the items.
     *
     * The callback should return an associative array with a single key/value pair.
     *
     * @template TMapToDictionaryKey of array-key
     * @template TMapToDictionaryValue
     *
     * @param callable(TValue, TKey): array<TMapToDictionaryKey, TMapToDictionaryValue> $callback
     * @return static<TMapToDictionaryKey, array<int, TMapToDictionaryValue>>
     */
    public function mapToDictionary(callable $callback): static;

    /**
     * Run a grouping map over the items.
     *
     * The callback should return an associative array with a single key/value pair.
     *
     * @template TMapToGroupsKey of array-key
     * @template TMapToGroupsValue
     *
     * @param callable(TValue, TKey): array<TMapToGroupsKey, TMapToGroupsValue> $callback
     * @return static<TMapToGroupsKey, static<int, TMapToGroupsValue>>
     */
    public function mapToGroups(callable $callback): static;

    /**
     * Run an associative map over each of the items.
     *
     * The callback should return an associative array with a single key/value pair.
     *
     * @template TMapWithKeysKey of array-key
     * @template TMapWithKeysValue
     *
     * @param callable(TValue, TKey): array<TMapWithKeysKey, TMapWithKeysValue> $callback
     * @return static<TMapWithKeysKey, TMapWithKeysValue>
     */
    public function mapWithKeys(callable $callback);

    /**
     * Map a collection and flatten the result by a single level.
     *
     * No return type: Eloquent\Collection::collapse() returns base collection,
     * which would violate `: static` when called on Eloquent\Collection.
     *
     * @template TFlatMapKey of array-key
     * @template TFlatMapValue
     *
     * @param callable(TValue, TKey): (array<TFlatMapKey, TFlatMapValue>|Collection<TFlatMapKey, TFlatMapValue>) $callback
     * @return static<TFlatMapKey, TFlatMapValue>
     */
    public function flatMap(callable $callback);

    /**
     * Map the values into a new class.
     *
     * @template TMapIntoValue
     *
     * @param class-string<TMapIntoValue> $class
     * @return static<TKey, TMapIntoValue>
     */
    public function mapInto(string $class);

    /**
     * Merge the collection with the given items.
     *
     * @template TMergeValue
     *
     * @param Arrayable<TKey, TMergeValue>|iterable<TKey, TMergeValue> $items
     * @return static<TKey, TMergeValue|TValue>
     */
    public function merge(Arrayable|iterable $items): static;

    /**
     * Recursively merge the collection with the given items.
     *
     * @template TMergeRecursiveValue
     *
     * @param Arrayable<TKey, TMergeRecursiveValue>|iterable<TKey, TMergeRecursiveValue> $items
     * @return static<TKey, TMergeRecursiveValue|TValue>
     */
    public function mergeRecursive(Arrayable|iterable $items): static;

    /**
     * Create a collection by using this collection for keys and another for its values.
     *
     * @template TCombineValue
     *
     * @param Arrayable<array-key, TCombineValue>|iterable<array-key, TCombineValue> $values
     * @return static<TValue, TCombineValue>
     * @phpstan-ignore generics.notSubtype (TValue becomes key - only valid when TValue is array-key, but can't express this constraint)
     */
    public function combine(Arrayable|iterable $values): static;

    /**
     * Union the collection with the given items.
     *
     * @param Arrayable<TKey, TValue>|iterable<TKey, TValue> $items
     */
    public function union(Arrayable|iterable $items): static;

    /**
     * Get the min value of a given key.
     *
     * @param null|(callable(TValue):mixed)|string $callback
     */
    public function min(callable|string|null $callback = null): mixed;

    /**
     * Get the max value of a given key.
     *
     * @param null|(callable(TValue):mixed)|string $callback
     */
    public function max(callable|string|null $callback = null): mixed;

    /**
     * Create a new collection consisting of every n-th element.
     */
    public function nth(int $step, int $offset = 0): static;

    /**
     * Get the items with the specified keys.
     *
     * @param array<array-key, TKey>|Enumerable<array-key, TKey>|string $keys
     */
    public function only(Enumerable|array|string $keys): static;

    /**
     * "Paginate" the collection by slicing it into a smaller collection.
     */
    public function forPage(int $page, int $perPage): static;

    /**
     * Partition the collection into two arrays using the given callback or key.
     *
     * @param (callable(TValue, TKey): bool)|string|TValue $key
     * @return static<int<0, 1>, static<TKey, TValue>>
     */
    public function partition(mixed $key, mixed $operator = null, mixed $value = null);

    /**
     * Push all of the given items onto the collection.
     *
     * @template TConcatKey of array-key
     * @template TConcatValue
     *
     * @param iterable<TConcatKey, TConcatValue> $source
     * @return static<TConcatKey|TKey, TConcatValue|TValue>
     */
    public function concat(iterable $source): static;

    /**
     * Get one or a specified number of items randomly from the collection.
     *
     * @return static<int, TValue>|TValue
     *
     * @throws InvalidArgumentException
     */
    public function random(?int $number = null): mixed;

    /**
     * Reduce the collection to a single value.
     *
     * @template TReduceInitial
     * @template TReduceReturnType
     *
     * @param callable(TReduceInitial|TReduceReturnType, TValue, TKey): TReduceReturnType $callback
     * @param TReduceInitial $initial
     * @return TReduceInitial|TReduceReturnType
     */
    public function reduce(callable $callback, mixed $initial = null): mixed;

    /**
     * Reduce the collection to multiple aggregate values.
     *
     * @throws UnexpectedValueException
     */
    public function reduceSpread(callable $callback, mixed ...$initial): array;

    /**
     * Replace the collection items with the given items.
     *
     * @param Arrayable<TKey, TValue>|iterable<TKey, TValue> $items
     */
    public function replace(Arrayable|iterable $items): static;

    /**
     * Recursively replace the collection items with the given items.
     *
     * @param Arrayable<TKey, TValue>|iterable<TKey, TValue> $items
     */
    public function replaceRecursive(Arrayable|iterable $items): static;

    /**
     * Reverse items order.
     */
    public function reverse(): static;

    /**
     * Search the collection for a given value and return the corresponding key if successful.
     *
     * @param (callable(TValue,TKey): bool)|TValue $value
     * @return false|TKey
     */
    public function search(mixed $value, bool $strict = false): mixed;

    /**
     * Get the item before the given item.
     *
     * @param (callable(TValue,TKey): bool)|TValue $value
     * @return null|TValue
     */
    public function before(mixed $value, bool $strict = false): mixed;

    /**
     * Get the item after the given item.
     *
     * @param (callable(TValue,TKey): bool)|TValue $value
     * @return null|TValue
     */
    public function after(mixed $value, bool $strict = false): mixed;

    /**
     * Shuffle the items in the collection.
     */
    public function shuffle(): static;

    /**
     * Create chunks representing a "sliding window" view of the items in the collection.
     *
     * @return static<int, static>
     */
    public function sliding(int $size = 2, int $step = 1): static;

    /**
     * Skip the first {$count} items.
     */
    public function skip(int $count): static;

    /**
     * Skip items in the collection until the given condition is met.
     *
     * @param (callable(TValue,TKey): bool)|TValue $value
     */
    public function skipUntil(mixed $value): static;

    /**
     * Skip items in the collection while the given condition is met.
     *
     * @param (callable(TValue,TKey): bool)|TValue $value
     */
    public function skipWhile(mixed $value): static;

    /**
     * Get a slice of items from the enumerable.
     */
    public function slice(int $offset, ?int $length = null): static;

    /**
     * Split a collection into a certain number of groups.
     *
     * @return static<int, static>
     */
    public function split(int $numberOfGroups): static;

    /**
     * Get the first item in the collection, but only if exactly one item exists. Otherwise, throw an exception.
     *
     * @param null|(callable(TValue, TKey): bool)|string $key
     * @return TValue
     *
     * @throws ItemNotFoundException
     * @throws MultipleItemsFoundException
     */
    public function sole(callable|string|null $key = null, mixed $operator = null, mixed $value = null): mixed;

    /**
     * Get the first item in the collection but throw an exception if no matching items exist.
     *
     * @param null|(callable(TValue, TKey): bool)|string $key
     * @return TValue
     *
     * @throws ItemNotFoundException
     */
    public function firstOrFail(callable|string|null $key = null, mixed $operator = null, mixed $value = null): mixed;

    /**
     * Chunk the collection into chunks of the given size.
     *
     * @return static<int, static>
     */
    public function chunk(int $size): static;

    /**
     * Chunk the collection into chunks with a callback.
     *
     * @param callable(TValue, TKey, static<int, TValue>): bool $callback
     * @return static<int, static<int, TValue>>
     */
    public function chunkWhile(callable $callback): static;

    /**
     * Split a collection into a certain number of groups, and fill the first groups completely.
     *
     * @return static<int, static>
     */
    public function splitIn(int $numberOfGroups): static;

    /**
     * Sort through each item with a callback.
     *
     * @param null|(callable(TValue, TValue): int)|int $callback
     */
    public function sort(callable|int|null $callback = null): static;

    /**
     * Sort items in descending order.
     */
    public function sortDesc(int $options = SORT_REGULAR): static;

    /**
     * Sort the collection using the given callback.
     *
     * @param array<array-key, array{string, string}|(callable(TValue, TKey): mixed)|(callable(TValue, TValue): mixed)|string>|(callable(TValue, TKey): mixed)|string $callback
     */
    public function sortBy(array|callable|string $callback, int $options = SORT_REGULAR, bool $descending = false): static;

    /**
     * Sort the collection in descending order using the given callback.
     *
     * @param array<array-key, array{string, string}|(callable(TValue, TKey): mixed)|(callable(TValue, TValue): mixed)|string>|(callable(TValue, TKey): mixed)|string $callback
     */
    public function sortByDesc(array|callable|string $callback, int $options = SORT_REGULAR): static;

    /**
     * Sort the collection keys.
     */
    public function sortKeys(int $options = SORT_REGULAR, bool $descending = false): static;

    /**
     * Sort the collection keys in descending order.
     */
    public function sortKeysDesc(int $options = SORT_REGULAR): static;

    /**
     * Sort the collection keys using a callback.
     *
     * @param callable(TKey, TKey): int $callback
     */
    public function sortKeysUsing(callable $callback): static;

    /**
     * Get the sum of the given values.
     *
     * @param null|(callable(TValue): mixed)|string $callback
     */
    public function sum(callable|string|null $callback = null): mixed;

    /**
     * Take the first or last {$limit} items.
     */
    public function take(int $limit): static;

    /**
     * Take items in the collection until the given condition is met.
     *
     * @param (callable(TValue,TKey): bool)|TValue $value
     */
    public function takeUntil(mixed $value): static;

    /**
     * Take items in the collection while the given condition is met.
     *
     * @param (callable(TValue,TKey): bool)|TValue $value
     */
    public function takeWhile(mixed $value): static;

    /**
     * Pass the collection to the given callback and then return it.
     *
     * @param callable(TValue): mixed $callback
     */
    public function tap(callable $callback): static;

    /**
     * Pass the enumerable to the given callback and return the result.
     *
     * @template TPipeReturnType
     *
     * @param callable($this): TPipeReturnType $callback
     * @return TPipeReturnType
     */
    public function pipe(callable $callback): mixed;

    /**
     * Pass the collection into a new class.
     *
     * @template TPipeIntoValue
     *
     * @param class-string<TPipeIntoValue> $class
     * @return TPipeIntoValue
     */
    public function pipeInto(string $class): mixed;

    /**
     * Pass the collection through a series of callable pipes and return the result.
     *
     * @param array<callable> $pipes
     */
    public function pipeThrough(array $pipes): mixed;

    /**
     * Get the values of a given key.
     *
     * @param array<array-key, string>|string $value
     * @return static<array-key, mixed>
     */
    public function pluck(string|array $value, ?string $key = null);

    /**
     * Create a collection of all elements that do not pass a given truth test.
     *
     * @param bool|(callable(TValue, TKey): bool)|TValue $callback
     */
    public function reject(mixed $callback = true): static;

    /**
     * Convert a flatten "dot" notation array into an expanded array.
     */
    public function undot(): static;

    /**
     * Return only unique items from the collection array.
     *
     * @param null|(callable(TValue, TKey): mixed)|string $key
     */
    public function unique(callable|string|null $key = null, bool $strict = false): static;

    /**
     * Return only unique items from the collection array using strict comparison.
     *
     * @param null|(callable(TValue, TKey): mixed)|string $key
     */
    public function uniqueStrict(callable|string|null $key = null): static;

    /**
     * Reset the keys on the underlying array.
     *
     * @return static<int, TValue>
     */
    public function values(): static;

    /**
     * Pad collection to the specified length with a value.
     *
     * @template TPadValue
     *
     * @param TPadValue $value
     * @return static<int, TPadValue|TValue>
     */
    public function pad(int $size, mixed $value);

    /**
     * Get the values iterator.
     *
     * @return Traversable<TKey, TValue>
     */
    public function getIterator(): Traversable;

    /**
     * Count the number of items in the collection.
     */
    public function count(): int;

    /**
     * Count the number of items in the collection by a field or using a callback.
     *
     * @param null|(callable(TValue, TKey): array-key)|string $countBy
     * @return static<array-key, int>
     */
    public function countBy(callable|string|null $countBy = null);

    /**
     * Zip the collection together with one or more arrays.
     *
     * e.g. new Collection([1, 2, 3])->zip([4, 5, 6]);
     *      => [[1, 4], [2, 5], [3, 6]]
     *
     * @template TZipValue
     *
     * @param Arrayable<array-key, TZipValue>|iterable<array-key, TZipValue> ...$items
     * @return static<int, static<int, TValue|TZipValue>>
     */
    public function zip(Arrayable|iterable ...$items);

    /**
     * Collect the values into a collection.
     *
     * @return Collection<TKey, TValue>
     */
    public function collect(): Collection;

    /**
     * Get the collection of items as a plain array.
     *
     * @return array<TKey, mixed>
     */
    public function toArray(): array;

    /**
     * Convert the object into something JSON serializable.
     */
    public function jsonSerialize(): mixed;

    /**
     * Get the collection of items as JSON.
     */
    public function toJson(int $options = 0): string;

    /**
     * Get the collection of items as pretty print formatted JSON.
     */
    public function toPrettyJson(int $options = 0): string;

    /**
     * Get a CachingIterator instance.
     */
    public function getCachingIterator(int $flags = CachingIterator::CALL_TOSTRING): CachingIterator;

    /**
     * Convert the collection to its string representation.
     */
    public function __toString(): string;

    /**
     * Indicate that the model's string representation should be escaped when __toString is invoked.
     */
    public function escapeWhenCastingToString(bool $escape = true): static;

    /**
     * Add a method to the list of proxied methods.
     */
    public static function proxy(string $method): void;

    /**
     * Dynamically access collection proxies.
     *
     * @throws Exception
     */
    public function __get(string $key): mixed;
}
