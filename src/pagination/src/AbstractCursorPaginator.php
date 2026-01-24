<?php

declare(strict_types=1);

namespace Hypervel\Pagination;

use ArrayAccess;
use Closure;
use Exception;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\ForwardsCalls;
use Hypervel\Support\Traits\Tappable;
use Hypervel\Support\Traits\TransformsToResourceCollection;
use Stringable;
use Traversable;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @mixin Collection<TKey, TValue>
 */
abstract class AbstractCursorPaginator implements Htmlable, Stringable
{
    use ForwardsCalls, Tappable, TransformsToResourceCollection;

    /**
     * Render the paginator using the given view.
     *
     * @param  array<string, mixed>  $data
     */
    abstract public function render(?string $view = null, array $data = []): Htmlable;

    /**
     * Indicates whether there are more items in the data source.
     */
    protected bool $hasMore;

    /**
     * All of the items being paginated.
     *
     * @var Collection<TKey, TValue>
     */
    protected Collection $items;

    /**
     * The number of items to be shown per page.
     */
    protected int $perPage;

    /**
     * The base path to assign to all URLs.
     */
    protected string $path = '/';

    /**
     * The query parameters to add to all URLs.
     *
     * @var array<string, mixed>
     */
    protected array $query = [];

    /**
     * The URL fragment to add to all URLs.
     */
    protected ?string $fragment = null;

    /**
     * The cursor string variable used to store the page.
     */
    protected string $cursorName = 'cursor';

    /**
     * The current cursor.
     */
    protected ?Cursor $cursor = null;

    /**
     * The paginator parameters for the cursor.
     *
     * @var array<int, string>
     */
    protected array $parameters;

    /**
     * The paginator options.
     *
     * @var array<string, mixed>
     */
    protected array $options;

    /**
     * The current cursor resolver callback.
     */
    protected static ?Closure $currentCursorResolver = null;

    /**
     * Get the URL for a given cursor.
     */
    public function url(?Cursor $cursor): string
    {
        // If we have any extra query string key / value pairs that need to be added
        // onto the URL, we will put them in query string form and then attach it
        // to the URL. This allows for extra information like sortings storage.
        $parameters = is_null($cursor) ? [] : [$this->cursorName => $cursor->encode()];

        if (count($this->query) > 0) {
            $parameters = array_merge($this->query, $parameters);
        }

        return $this->path()
            .(str_contains($this->path(), '?') ? '&' : '?')
            .Arr::query($parameters)
            .$this->buildFragment();
    }

    /**
     * Get the URL for the previous page.
     */
    public function previousPageUrl(): ?string
    {
        if (is_null($previousCursor = $this->previousCursor())) {
            return null;
        }

        return $this->url($previousCursor);
    }

    /**
     * The URL for the next page, or null.
     */
    public function nextPageUrl(): ?string
    {
        if (is_null($nextCursor = $this->nextCursor())) {
            return null;
        }

        return $this->url($nextCursor);
    }

    /**
     * Get the "cursor" that points to the previous set of items.
     */
    public function previousCursor(): ?Cursor
    {
        if (is_null($this->cursor) ||
            ($this->cursor->pointsToPreviousItems() && ! $this->hasMore)) {
            return null;
        }

        if ($this->items->isEmpty()) {
            return null;
        }

        return $this->getCursorForItem($this->items->first(), false);
    }

    /**
     * Get the "cursor" that points to the next set of items.
     */
    public function nextCursor(): ?Cursor
    {
        if ((is_null($this->cursor) && ! $this->hasMore) ||
            (! is_null($this->cursor) && $this->cursor->pointsToNextItems() && ! $this->hasMore)) {
            return null;
        }

        if ($this->items->isEmpty()) {
            return null;
        }

        return $this->getCursorForItem($this->items->last(), true);
    }

    /**
     * Get a cursor instance for the given item.
     */
    public function getCursorForItem(object $item, bool $isNext = true): Cursor
    {
        return new Cursor($this->getParametersForItem($item), $isNext);
    }

    /**
     * Get the cursor parameters for a given object.
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function getParametersForItem(object $item): array
    {
        /** @var Collection<string, int> $flipped */
        $flipped = (new Collection($this->parameters))->filter()->flip();

        return $flipped->map(function (int $_, string $parameterName) use ($item) {
            if ($item instanceof JsonResource) {
                $item = $item->resource;
            }

            if ($item instanceof Model &&
                ! is_null($parameter = $this->getPivotParameterForItem($item, $parameterName))) {
                return $parameter;
            } elseif ($item instanceof ArrayAccess || is_array($item)) {
                return $this->ensureParameterIsPrimitive(
                    $item[$parameterName] ?? $item[Str::afterLast($parameterName, '.')]
                );
            } elseif (is_object($item)) {
                return $this->ensureParameterIsPrimitive(
                    $item->{$parameterName} ?? $item->{Str::afterLast($parameterName, '.')}
                );
            }

            throw new Exception('Only arrays and objects are supported when cursor paginating items.');
        })->toArray();
    }

    /**
     * Get the cursor parameter value from a pivot model if applicable.
     */
    protected function getPivotParameterForItem(Model $item, string $parameterName): ?string
    {
        $table = Str::beforeLast($parameterName, '.');

        foreach ($item->getRelations() as $relation) {
            if ($relation instanceof Pivot && $relation->getTable() === $table) {
                return $this->ensureParameterIsPrimitive(
                    $relation->getAttribute(Str::afterLast($parameterName, '.'))
                );
            }
        }

        return null;
    }

    /**
     * Ensure the parameter is a primitive type.
     *
     * This can resolve issues that arise the developer uses a value object for an attribute.
     */
    protected function ensureParameterIsPrimitive(mixed $parameter): mixed
    {
        return is_object($parameter) && method_exists($parameter, '__toString')
            ? (string) $parameter
            : $parameter;
    }

    /**
     * Get / set the URL fragment to be appended to URLs.
     *
     * @return $this|string|null
     */
    public function fragment(?string $fragment = null): static|string|null
    {
        if (is_null($fragment)) {
            return $this->fragment;
        }

        $this->fragment = $fragment;

        return $this;
    }

    /**
     * Add a set of query string values to the paginator.
     *
     * @return $this
     */
    public function appends(array|string|null $key, ?string $value = null): static
    {
        if (is_null($key)) {
            return $this;
        }

        if (is_array($key)) {
            return $this->appendArray($key);
        }

        return $this->addQuery($key, $value);
    }

    /**
     * Add an array of query string values.
     *
     * @param  array<string, mixed>  $keys
     * @return $this
     */
    protected function appendArray(array $keys): static
    {
        foreach ($keys as $key => $value) {
            $this->addQuery($key, $value);
        }

        return $this;
    }

    /**
     * Add all current query string values to the paginator.
     *
     * @return $this
     */
    public function withQueryString(): static
    {
        if (! is_null($query = Paginator::resolveQueryString())) {
            return $this->appends($query);
        }

        return $this;
    }

    /**
     * Add a query string value to the paginator.
     *
     * @return $this
     */
    protected function addQuery(string $key, mixed $value): static
    {
        if ($key !== $this->cursorName) {
            $this->query[$key] = $value;
        }

        return $this;
    }

    /**
     * Build the full fragment portion of a URL.
     */
    protected function buildFragment(): string
    {
        return $this->fragment ? '#'.$this->fragment : '';
    }

    /**
     * Load a set of relationships onto the mixed relationship collection.
     *
     * @param  array<class-string, array<int, string>>  $relations
     * @return $this
     */
    public function loadMorph(string $relation, array $relations): static
    {
        /** @phpstan-ignore method.notFound (loadMorph exists on Eloquent Collection, not base Collection) */
        $this->getCollection()->loadMorph($relation, $relations);

        return $this;
    }

    /**
     * Load a set of relationship counts onto the mixed relationship collection.
     *
     * @param  array<class-string, array<int, string>>  $relations
     * @return $this
     */
    public function loadMorphCount(string $relation, array $relations): static
    {
        /** @phpstan-ignore method.notFound (loadMorphCount exists on Eloquent Collection, not base Collection) */
        $this->getCollection()->loadMorphCount($relation, $relations);

        return $this;
    }

    /**
     * Get the slice of items being paginated.
     *
     * @return array<TKey, TValue>
     */
    public function items(): array
    {
        return $this->items->all();
    }

    /**
     * Transform each item in the slice of items using a callback.
     *
     * @template TThroughValue
     *
     * @param  callable(TValue, TKey): TThroughValue  $callback
     * @return $this
     *
     * @phpstan-this-out static<TKey, TThroughValue>
     */
    public function through(callable $callback): static
    {
        $this->items->transform($callback);

        return $this;
    }

    /**
     * Get the number of items shown per page.
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Get the current cursor being paginated.
     */
    public function cursor(): ?Cursor
    {
        return $this->cursor;
    }

    /**
     * Get the query string variable used to store the cursor.
     */
    public function getCursorName(): string
    {
        return $this->cursorName;
    }

    /**
     * Set the query string variable used to store the cursor.
     *
     * @return $this
     */
    public function setCursorName(string $name): static
    {
        $this->cursorName = $name;

        return $this;
    }

    /**
     * Set the base path to assign to all URLs.
     *
     * @return $this
     */
    public function withPath(string $path): static
    {
        return $this->setPath($path);
    }

    /**
     * Set the base path to assign to all URLs.
     *
     * @return $this
     */
    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Get the base path for paginator generated URLs.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * Resolve the current cursor or return the default value.
     */
    public static function resolveCurrentCursor(string $cursorName = 'cursor', ?Cursor $default = null): ?Cursor
    {
        if (isset(static::$currentCursorResolver)) {
            return call_user_func(static::$currentCursorResolver, $cursorName);
        }

        return $default;
    }

    /**
     * Set the current cursor resolver callback.
     */
    public static function currentCursorResolver(Closure $resolver): void
    {
        static::$currentCursorResolver = $resolver;
    }

    /**
     * Get an instance of the view factory from the resolver.
     */
    public static function viewFactory(): mixed
    {
        return Paginator::viewFactory();
    }

    /**
     * Get an iterator for the items.
     *
     * @return \ArrayIterator<TKey, TValue>
     */
    public function getIterator(): Traversable
    {
        return $this->items->getIterator();
    }

    /**
     * Determine if the list of items is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /**
     * Determine if the list of items is not empty.
     */
    public function isNotEmpty(): bool
    {
        return $this->items->isNotEmpty();
    }

    /**
     * Get the number of items for the current page.
     */
    public function count(): int
    {
        return $this->items->count();
    }

    /**
     * Get the paginator's underlying collection.
     *
     * @return Collection<TKey, TValue>
     */
    public function getCollection(): Collection
    {
        return $this->items;
    }

    /**
     * Set the paginator's underlying collection.
     *
     * @template TSetKey of array-key
     * @template TSetValue
     *
     * @param  Collection<TSetKey, TSetValue>  $collection
     * @return $this
     *
     * @phpstan-this-out static<TSetKey, TSetValue>
     */
    public function setCollection(Collection $collection): static
    {
        /** @phpstan-ignore assign.propertyType */
        $this->items = $collection;

        return $this;
    }

    /**
     * Get the paginator options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Determine if the given item exists.
     *
     * @param  TKey  $key
     * @return bool
     */
    public function offsetExists($key): bool
    {
        return $this->items->has($key);
    }

    /**
     * Get the item at the given offset.
     *
     * @param  TKey  $key
     * @return TValue|null
     */
    public function offsetGet($key): mixed
    {
        return $this->items->get($key);
    }

    /**
     * Set the item at the given offset.
     *
     * @param  TKey|null  $key
     * @param  TValue  $value
     * @return void
     */
    public function offsetSet($key, $value): void
    {
        $this->items->put($key, $value);
    }

    /**
     * Unset the item at the given key.
     *
     * @param  TKey  $key
     * @return void
     */
    public function offsetUnset($key): void
    {
        $this->items->forget($key);
    }

    /**
     * Render the contents of the paginator to HTML.
     */
    public function toHtml(): string
    {
        return (string) $this->render();
    }

    /**
     * Make dynamic calls into the collection.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->getCollection(), $method, $parameters);
    }

    /**
     * Render the contents of the paginator when casting to a string.
     */
    public function __toString(): string
    {
        return (string) $this->render();
    }
}
