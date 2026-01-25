<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Pagination;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @extends Paginator<TKey, TValue>
 */
interface LengthAwarePaginator extends Paginator
{
    /**
     * Create a range of pagination URLs.
     *
     * @return array<int, string>
     */
    public function getUrlRange(int $start, int $end): array;

    /**
     * Determine the total number of items in the data store.
     */
    public function total(): int;

    /**
     * Get the page number of the last available page.
     */
    public function lastPage(): int;
}
