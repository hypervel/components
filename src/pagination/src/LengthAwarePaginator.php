<?php

declare(strict_types=1);

namespace Hypervel\Pagination;

use ArrayAccess;
use Countable;
use Hypervel\Support\Collection;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Pagination\Contracts\LengthAwarePaginator as LengthAwarePaginatorContract;
use Hypervel\Support\Contracts\Htmlable;
use IteratorAggregate;
use JsonSerializable;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @extends AbstractPaginator<TKey, TValue>
 *
 * @implements Arrayable<TKey, TValue>
 * @implements ArrayAccess<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 * @implements LengthAwarePaginatorContract<TKey, TValue>
 */
class LengthAwarePaginator extends AbstractPaginator implements Arrayable, ArrayAccess, Countable, IteratorAggregate, Jsonable, JsonSerializable, LengthAwarePaginatorContract
{
    /**
     * The total number of items before slicing.
     */
    protected int $total;

    /**
     * The last available page.
     */
    protected int $lastPage;

    /**
     * Create a new paginator instance.
     *
     * @param  Collection<TKey, TValue>|Arrayable<TKey, TValue>|iterable<TKey, TValue>|null  $items
     * @param  array<string, mixed>  $options  (path, query, fragment, pageName)
     */
    public function __construct(mixed $items, int $total, int $perPage, ?int $currentPage = null, array $options = [])
    {
        $this->options = $options;

        foreach ($options as $key => $value) {
            $this->{$key} = $value;
        }

        $this->total = $total;
        $this->perPage = $perPage;
        $this->lastPage = max((int) ceil($total / $perPage), 1);
        $this->path = $this->path !== '/' ? rtrim($this->path, '/') : $this->path;
        $this->currentPage = $this->setCurrentPage($currentPage, $this->pageName);
        $this->items = $items instanceof Collection ? $items : new Collection($items);
    }

    /**
     * Get the current page for the request.
     */
    protected function setCurrentPage(?int $currentPage, string $pageName): int
    {
        $currentPage = $currentPage ?: static::resolveCurrentPage($pageName);

        return $this->isValidPageNumber($currentPage) ? (int) $currentPage : 1;
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
        return static::viewFactory()->make($view ?: static::$defaultView, array_merge($data, [
            'paginator' => $this,
            'elements' => $this->elements(),
        ]));
    }

    /**
     * Get the paginator links as a collection (for JSON responses).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function linkCollection(): Collection
    {
        /** @var Collection<int, array<string, mixed>> */
        return (new Collection($this->elements()))->flatMap(function ($item) {
            if (! is_array($item)) {
                return [['url' => null, 'label' => '...', 'active' => false]];
            }

            return (new Collection($item))->map(function ($url, $page) {
                return [
                    'url' => $url,
                    'label' => (string) $page,
                    'page' => $page,
                    'active' => $this->currentPage() === $page,
                ];
            });
        })->prepend([
            'url' => $this->previousPageUrl(),
            'label' => function_exists('__') ? __('pagination.previous') : 'Previous',
            'page' => $this->currentPage() > 1 ? $this->currentPage() - 1 : null,
            'active' => false,
        ])->push([
            'url' => $this->nextPageUrl(),
            'label' => function_exists('__') ? __('pagination.next') : 'Next',
            'page' => $this->hasMorePages() ? $this->currentPage() + 1 : null,
            'active' => false,
        ]);
    }

    /**
     * Get the array of elements to pass to the view.
     *
     * @return array<int, mixed>
     */
    protected function elements(): array
    {
        $window = UrlWindow::make($this);

        return array_filter([
            $window['first'],
            is_array($window['slider']) ? '...' : null,
            $window['slider'],
            is_array($window['last']) ? '...' : null,
            $window['last'],
        ]);
    }

    /**
     * Get the total number of items being paginated.
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Determine if there are more items in the data source.
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage() < $this->lastPage();
    }

    /**
     * Get the URL for the next page.
     */
    public function nextPageUrl(): ?string
    {
        if ($this->hasMorePages()) {
            return $this->url($this->currentPage() + 1);
        }

        return null;
    }

    /**
     * Get the last page.
     */
    public function lastPage(): int
    {
        return $this->lastPage;
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'current_page' => $this->currentPage(),
            'data' => $this->items->toArray(),
            'first_page_url' => $this->url(1),
            'from' => $this->firstItem(),
            'last_page' => $this->lastPage(),
            'last_page_url' => $this->url($this->lastPage()),
            'links' => $this->linkCollection()->toArray(),
            'next_page_url' => $this->nextPageUrl(),
            'path' => $this->path(),
            'per_page' => $this->perPage(),
            'prev_page_url' => $this->previousPageUrl(),
            'to' => $this->lastItem(),
            'total' => $this->total(),
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
