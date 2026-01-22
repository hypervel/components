<?php

declare(strict_types=1);

namespace Hypervel\Pagination\Contracts;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @method $this through(callable(TValue): mixed $callback)
 */
interface Paginator
{
    /**
     * Get the URL for a given page.
     */
    public function url(int $page): string;

    /**
     * Add a set of query string values to the paginator.
     *
     * @return $this
     */
    public function appends(array|string|null $key, ?string $value = null): static;

    /**
     * Get / set the URL fragment to be appended to URLs.
     *
     * @return $this|string|null
     */
    public function fragment(?string $fragment = null): static|string|null;

    /**
     * Add all current query string values to the paginator.
     *
     * @return $this
     */
    public function withQueryString(): static;

    /**
     * The URL for the next page, or null.
     */
    public function nextPageUrl(): ?string;

    /**
     * Get the URL for the previous page, or null.
     */
    public function previousPageUrl(): ?string;

    /**
     * Get all of the items being paginated.
     *
     * @return array<TKey, TValue>
     */
    public function items(): array;

    /**
     * Get the "index" of the first item being paginated.
     */
    public function firstItem(): ?int;

    /**
     * Get the "index" of the last item being paginated.
     */
    public function lastItem(): ?int;

    /**
     * Determine how many items are being shown per page.
     */
    public function perPage(): int;

    /**
     * Determine the current page being paginated.
     */
    public function currentPage(): int;

    /**
     * Determine if there are enough items to split into multiple pages.
     */
    public function hasPages(): bool;

    /**
     * Determine if there are more items in the data store.
     */
    public function hasMorePages(): bool;

    /**
     * Get the base path for paginator generated URLs.
     */
    public function path(): ?string;

    /**
     * Determine if the list of items is empty or not.
     */
    public function isEmpty(): bool;

    /**
     * Determine if the list of items is not empty.
     */
    public function isNotEmpty(): bool;

    /**
     * Render the paginator using a given view.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(?string $view = null, array $data = []): mixed;
}
