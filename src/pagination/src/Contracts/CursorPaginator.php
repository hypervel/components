<?php

declare(strict_types=1);

namespace Hypervel\Pagination\Contracts;

use Hypervel\Pagination\Cursor;

/**
 * @template TKey of array-key
 * @template-covariant TValue
 *
 * @method $this through(callable(TValue): mixed $callback)
 */
interface CursorPaginator
{
    /**
     * Get the URL for a given cursor.
     */
    public function url(?Cursor $cursor): string;

    /**
     * Add a set of query string values to the paginator.
     *
     * @param array<string, mixed>|string|null $key
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
     * Get the URL for the previous page, or null.
     */
    public function previousPageUrl(): ?string;

    /**
     * The URL for the next page, or null.
     */
    public function nextPageUrl(): ?string;

    /**
     * Get all of the items being paginated.
     *
     * @return array<TKey, TValue>
     */
    public function items(): array;

    /**
     * Get the "cursor" of the previous set of items.
     */
    public function previousCursor(): ?Cursor;

    /**
     * Get the "cursor" of the next set of items.
     */
    public function nextCursor(): ?Cursor;

    /**
     * Determine how many items are being shown per page.
     */
    public function perPage(): int;

    /**
     * Get the current cursor being paginated.
     */
    public function cursor(): ?Cursor;

    /**
     * Determine if there are enough items to split into multiple pages.
     */
    public function hasPages(): bool;

    /**
     * Determine if there are more items in the data source.
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
     * @param array<string, mixed> $data
     */
    public function render(?string $view = null, array $data = []): string;
}
