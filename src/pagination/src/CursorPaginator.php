<?php

declare(strict_types=1);

namespace Hypervel\Pagination;

use ArrayAccess;
use Countable;
use Hypervel\Collection\Collection;
use Hypervel\Support\Contracts\Arrayable;
use Hypervel\Support\Contracts\Jsonable;
use Hypervel\Pagination\Contracts\CursorPaginator as PaginatorContract;
use Hypervel\Support\Contracts\Htmlable;
use IteratorAggregate;
use JsonSerializable;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @extends AbstractCursorPaginator<TKey, TValue>
 *
 * @implements Arrayable<TKey, TValue>
 * @implements ArrayAccess<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 * @implements PaginatorContract<TKey, TValue>
 */
class CursorPaginator extends AbstractCursorPaginator implements Arrayable, ArrayAccess, Countable, IteratorAggregate, Jsonable, JsonSerializable, PaginatorContract
{
    /**
     * Indicates whether there are more items in the data source.
     */
    protected bool $hasMore;

    /**
     * Create a new paginator instance.
     *
     * @param  Collection<TKey, TValue>|Arrayable<TKey, TValue>|iterable<TKey, TValue>|null  $items
     * @param  array<string, mixed>  $options  (path, query, fragment, pageName)
     */
    public function __construct(mixed $items, int $perPage, ?Cursor $cursor = null, array $options = [])
    {
        $this->options = $options;

        foreach ($options as $key => $value) {
            $this->{$key} = $value;
        }

        $this->perPage = $perPage;
        $this->cursor = $cursor;
        $this->path = $this->path !== '/' ? rtrim($this->path, '/') : $this->path;

        $this->setItems($items);
    }

    /**
     * Set the items for the paginator.
     *
     * @param  Collection<TKey, TValue>|Arrayable<TKey, TValue>|iterable<TKey, TValue>|null  $items
     */
    protected function setItems(mixed $items): void
    {
        $this->items = $items instanceof Collection ? $items : new Collection($items);

        $this->hasMore = $this->items->count() > $this->perPage;

        $this->items = $this->items->slice(0, $this->perPage);

        if (! is_null($this->cursor) && $this->cursor->pointsToPreviousItems()) {
            $this->items = $this->items->reverse()->values();
        }
    }

    /**
     * Render the paginator using the given view.
     *
     * @param  array<string, mixed>  $data
     */
    public function links(?string $view = null, array $data = []): Htmlable
    {
        return $this->render($view, $data);
    }

    /**
     * Render the paginator using the given view.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(?string $view = null, array $data = []): Htmlable
    {
        return static::viewFactory()->make($view ?: Paginator::$defaultSimpleView, array_merge($data, [
            'paginator' => $this,
        ]));
    }

    /**
     * Determine if there are more items in the data source.
     */
    public function hasMorePages(): bool
    {
        return (is_null($this->cursor) && $this->hasMore) ||
            (! is_null($this->cursor) && $this->cursor->pointsToNextItems() && $this->hasMore) ||
            (! is_null($this->cursor) && $this->cursor->pointsToPreviousItems());
    }

    /**
     * Determine if there are enough items to split into multiple pages.
     */
    public function hasPages(): bool
    {
        return ! $this->onFirstPage() || $this->hasMorePages();
    }

    /**
     * Determine if the paginator is on the first page.
     */
    public function onFirstPage(): bool
    {
        return is_null($this->cursor) || ($this->cursor->pointsToPreviousItems() && ! $this->hasMore);
    }

    /**
     * Determine if the paginator is on the last page.
     */
    public function onLastPage(): bool
    {
        return ! $this->hasMorePages();
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->items->toArray(),
            'path' => $this->path(),
            'per_page' => $this->perPage(),
            'next_cursor' => $this->nextCursor()?->encode(),
            'next_page_url' => $this->nextPageUrl(),
            'prev_cursor' => $this->previousCursor()?->encode(),
            'prev_page_url' => $this->previousPageUrl(),
        ];
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the object to its JSON representation.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Convert the object to pretty print formatted JSON.
     */
    public function toPrettyJson(int $options = 0): string
    {
        return $this->toJson(JSON_PRETTY_PRINT | $options);
    }
}
