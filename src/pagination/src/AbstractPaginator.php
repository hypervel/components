<?php

declare(strict_types=1);

namespace Hypervel\Pagination;

use Closure;
use Hypervel\Contracts\Support\CanBeEscapedWhenCastToString;
use Hypervel\Contracts\Support\Htmlable;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
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
abstract class AbstractPaginator implements CanBeEscapedWhenCastToString, Htmlable, Stringable
{
    use ForwardsCalls;
    use Tappable;
    use TransformsToResourceCollection;

    /**
     * Render the paginator using the given view.
     *
     * @param array<string, mixed> $data
     */
    abstract public function render(?string $view = null, array $data = []): Htmlable;

    /**
     * Determine if there are more items in the data source.
     */
    abstract public function hasMorePages(): bool;

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
     * The current page being "viewed".
     */
    protected int $currentPage;

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
     * The query string variable used to store the page.
     */
    protected string $pageName = 'page';

    /**
     * Indicates that the paginator's string representation should be escaped when __toString is invoked.
     */
    protected bool $escapeWhenCastingToString = false;

    /**
     * The number of links to display on each side of current page link.
     */
    public int $onEachSide = 3;

    /**
     * The paginator options.
     *
     * @var array<string, mixed>
     */
    protected array $options = [];

    /**
     * The current path resolver callback.
     */
    protected static ?Closure $currentPathResolver = null;

    /**
     * The current page resolver callback.
     */
    protected static ?Closure $currentPageResolver = null;

    /**
     * The query string resolver callback.
     */
    protected static ?Closure $queryStringResolver = null;

    /**
     * The view factory resolver callback.
     */
    protected static ?Closure $viewFactoryResolver = null;

    /**
     * The default pagination view.
     */
    public static string $defaultView = 'pagination::tailwind';

    /**
     * The default "simple" pagination view.
     */
    public static string $defaultSimpleView = 'pagination::simple-tailwind';

    /**
     * Determine if the given value is a valid page number.
     */
    protected function isValidPageNumber(int $page): bool
    {
        return $page >= 1;
    }

    /**
     * Get the URL for the previous page.
     */
    public function previousPageUrl(): ?string
    {
        if ($this->currentPage() > 1) {
            return $this->url($this->currentPage() - 1);
        }

        return null;
    }

    /**
     * Create a range of pagination URLs.
     *
     * @return array<int, string>
     */
    public function getUrlRange(int $start, int $end): array
    {
        return Collection::range($start, $end)
            ->mapWithKeys(fn ($page) => [$page => $this->url($page)])
            ->all();
    }

    /**
     * Get the URL for a given page number.
     */
    public function url(int $page): string
    {
        if ($page <= 0) {
            $page = 1;
        }

        // If we have any extra query string key / value pairs that need to be added
        // onto the URL, we will put them in query string form and then attach it
        // to the URL. This allows for extra information like sortings storage.
        $parameters = [$this->pageName => $page];

        if (count($this->query) > 0) {
            $parameters = array_merge($this->query, $parameters);
        }

        return $this->path()
                        . (str_contains($this->path(), '?') ? '&' : '?')
                        . Arr::query($parameters)
                        . $this->buildFragment();
    }

    /**
     * Get / set the URL fragment to be appended to URLs.
     *
     * @return null|$this|string
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
     * @param array<string, mixed> $keys
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
        if (isset(static::$queryStringResolver)) {
            return $this->appends(call_user_func(static::$queryStringResolver));
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
        if ($key !== $this->pageName) {
            $this->query[$key] = $value;
        }

        return $this;
    }

    /**
     * Build the full fragment portion of a URL.
     */
    protected function buildFragment(): string
    {
        return $this->fragment ? '#' . $this->fragment : '';
    }

    /**
     * Load a set of relationships onto the mixed relationship collection.
     *
     * @param array<class-string, array<int, string>> $relations
     * @return $this
     */
    public function loadMorph(string $relation, array $relations): static
    {
        /* @phpstan-ignore method.notFound (loadMorph exists on Eloquent Collection, not base Collection) */
        $this->getCollection()->loadMorph($relation, $relations);

        return $this;
    }

    /**
     * Load a set of relationship counts onto the mixed relationship collection.
     *
     * @param array<class-string, array<int, string>> $relations
     * @return $this
     */
    public function loadMorphCount(string $relation, array $relations): static
    {
        /* @phpstan-ignore method.notFound (loadMorphCount exists on Eloquent Collection, not base Collection) */
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
     * Get the number of the first item in the slice.
     */
    public function firstItem(): ?int
    {
        return count($this->items) > 0 ? ($this->currentPage - 1) * $this->perPage + 1 : null;
    }

    /**
     * Get the number of the last item in the slice.
     */
    public function lastItem(): ?int
    {
        return count($this->items) > 0 ? $this->firstItem() + $this->count() - 1 : null;
    }

    /**
     * Transform each item in the slice of items using a callback.
     *
     * @template TMapValue
     *
     * @param callable(TValue, TKey): TMapValue $callback
     * @return $this
     *
     * @phpstan-this-out static<TKey, TMapValue>
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
     * Determine if there are enough items to split into multiple pages.
     */
    public function hasPages(): bool
    {
        return $this->currentPage() != 1 || $this->hasMorePages();
    }

    /**
     * Determine if the paginator is on the first page.
     */
    public function onFirstPage(): bool
    {
        return $this->currentPage() <= 1;
    }

    /**
     * Determine if the paginator is on the last page.
     */
    public function onLastPage(): bool
    {
        return ! $this->hasMorePages();
    }

    /**
     * Get the current page.
     */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Get the query string variable used to store the page.
     */
    public function getPageName(): string
    {
        return $this->pageName;
    }

    /**
     * Set the query string variable used to store the page.
     *
     * @return $this
     */
    public function setPageName(string $name): static
    {
        $this->pageName = $name;

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
     * Set the number of links to display on each side of current page link.
     *
     * @return $this
     */
    public function onEachSide(int $count): static
    {
        $this->onEachSide = $count;

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
     * Resolve the current request path or return the default value.
     */
    public static function resolveCurrentPath(string $default = '/'): string
    {
        if (isset(static::$currentPathResolver)) {
            return call_user_func(static::$currentPathResolver);
        }

        return $default;
    }

    /**
     * Set the current request path resolver callback.
     */
    public static function currentPathResolver(Closure $resolver): void
    {
        static::$currentPathResolver = $resolver;
    }

    /**
     * Resolve the current page or return the default value.
     */
    public static function resolveCurrentPage(string $pageName = 'page', int $default = 1): int
    {
        if (isset(static::$currentPageResolver)) {
            return (int) call_user_func(static::$currentPageResolver, $pageName);
        }

        return $default;
    }

    /**
     * Set the current page resolver callback.
     */
    public static function currentPageResolver(Closure $resolver): void
    {
        static::$currentPageResolver = $resolver;
    }

    /**
     * Resolve the query string or return the default value.
     */
    public static function resolveQueryString(string|array|null $default = null): string|array|null
    {
        if (isset(static::$queryStringResolver)) {
            return (static::$queryStringResolver)();
        }

        return $default;
    }

    /**
     * Set with query string resolver callback.
     */
    public static function queryStringResolver(Closure $resolver): void
    {
        static::$queryStringResolver = $resolver;
    }

    /**
     * Get an instance of the view factory from the resolver.
     */
    public static function viewFactory(): mixed
    {
        return call_user_func(static::$viewFactoryResolver);
    }

    /**
     * Set the view factory resolver callback.
     */
    public static function viewFactoryResolver(Closure $resolver): void
    {
        static::$viewFactoryResolver = $resolver;
    }

    /**
     * Set the default pagination view.
     */
    public static function defaultView(string $view): void
    {
        static::$defaultView = $view;
    }

    /**
     * Set the default "simple" pagination view.
     */
    public static function defaultSimpleView(string $view): void
    {
        static::$defaultSimpleView = $view;
    }

    /**
     * Indicate that Tailwind styling should be used for generated links.
     */
    public static function useTailwind(): void
    {
        static::defaultView('pagination::tailwind');
        static::defaultSimpleView('pagination::simple-tailwind');
    }

    /**
     * Indicate that Bootstrap 4 styling should be used for generated links.
     */
    public static function useBootstrap(): void
    {
        static::useBootstrapFour();
    }

    /**
     * Indicate that Bootstrap 3 styling should be used for generated links.
     */
    public static function useBootstrapThree(): void
    {
        static::defaultView('pagination::default');
        static::defaultSimpleView('pagination::simple-default');
    }

    /**
     * Indicate that Bootstrap 4 styling should be used for generated links.
     */
    public static function useBootstrapFour(): void
    {
        static::defaultView('pagination::bootstrap-4');
        static::defaultSimpleView('pagination::simple-bootstrap-4');
    }

    /**
     * Indicate that Bootstrap 5 styling should be used for generated links.
     */
    public static function useBootstrapFive(): void
    {
        static::defaultView('pagination::bootstrap-5');
        static::defaultSimpleView('pagination::simple-bootstrap-5');
    }

    /**
     * Get an iterator for the items.
     *
     * @return Traversable<TKey, TValue>
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
     * @param Collection<TKey, TValue> $collection
     * @return $this
     */
    public function setCollection(Collection $collection): static
    {
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
     * @param TKey $key
     */
    public function offsetExists($key): bool
    {
        return $this->items->has($key);
    }

    /**
     * Get the item at the given offset.
     *
     * @param TKey $key
     * @return null|TValue
     */
    public function offsetGet($key): mixed
    {
        return $this->items->get($key);
    }

    /**
     * Set the item at the given offset.
     *
     * @param null|TKey $key
     * @param TValue $value
     */
    public function offsetSet($key, $value): void
    {
        $this->items->put($key, $value);
    }

    /**
     * Unset the item at the given key.
     *
     * @param TKey $key
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
        $rendered = $this->render();

        return $rendered instanceof Stringable
            ? (string) $rendered
            : $rendered->toHtml();
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
        $rendered = $this->render();
        $renderedString = $rendered instanceof Stringable
            ? (string) $rendered
            : $rendered->toHtml();

        return $this->escapeWhenCastingToString
            ? e($renderedString)
            : $renderedString;
    }

    /**
     * Indicate that the paginator's string representation should be escaped when __toString is invoked.
     *
     * @return $this
     */
    public function escapeWhenCastingToString(bool $escape = true): static
    {
        $this->escapeWhenCastingToString = $escape;

        return $this;
    }
}
