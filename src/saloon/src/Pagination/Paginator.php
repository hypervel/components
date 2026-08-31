<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Pagination;

use Countable;
use Hypervel\Saloon\Exceptions\PoolException;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Pagination\Contracts\MapPaginatedResponseItems;
use Hypervel\Saloon\Pagination\Contracts\Paginatable;
use Hypervel\Saloon\Pagination\Exceptions\PaginationException;
use Hypervel\Support\LazyCollection;
use InvalidArgumentException;
use Iterator;
use LogicException;
use Throwable;

abstract class Paginator implements Countable, Iterator
{
    /**
     * The page number applied to the next request.
     */
    protected int $pageNumber = 1;

    /**
     * The zero-based iterator position.
     */
    protected int $currentPage = 0;

    /**
     * The first page number requested after rewind.
     */
    protected int $startPage = 1;

    /**
     * The optional maximum number of pages to request.
     */
    protected ?int $maxPages = null;

    /**
     * The optional result limit per page.
     */
    protected ?int $perPageLimit = null;

    /**
     * The current response.
     */
    protected ?Response $currentResponse = null;

    /**
     * The total number of mapped results processed.
     */
    protected int $totalResults = 0;

    /**
     * Whether repeated response bodies should stop iteration.
     */
    protected bool $detectInfiniteLoop = true;

    /**
     * Whether the paginator is currently preparing pooled pages.
     */
    protected bool $pooling = false;

    /**
     * The latest response body checksums.
     *
     * @var list<string>
     */
    protected array $lastFiveBodyChecksums = [];

    /**
     * Create a paginator.
     */
    public function __construct(
        protected Connector $connector,
        protected Request $request,
    ) {
        if (! $request instanceof Paginatable) {
            throw new InvalidArgumentException(sprintf(
                'The request must implement [%s] to be used with a paginator.',
                Paginatable::class,
            ));
        }

        $this->request = clone $request;
        $this->request->middleware()
            ->onResponse(static fn (Response $response): Response => $response->throw())
            ->onResponse(function (Response $response): void {
                $this->totalResults += count($this->pageItems($response));
            })
            ->onResponse(function (Response $response): void {
                if (! $this->detectInfiniteLoop || $this->pooling) {
                    return;
                }

                $this->lastFiveBodyChecksums[] = hash('xxh128', $response->body());

                if (count($this->lastFiveBodyChecksums) < 5) {
                    return;
                }

                if (count(array_unique($this->lastFiveBodyChecksums)) === 1) {
                    throw new PaginationException(
                        'Potential infinite loop detected because the last five responses had the same body.',
                    );
                }

                array_shift($this->lastFiveBodyChecksums);
            });
    }

    /**
     * Get the response for the current page.
     */
    public function current(): Response
    {
        $request = $this->applyPagination(clone $this->request);

        return $this->currentResponse = $this->connector->send($request);
    }

    /**
     * Move to the next page.
     */
    public function next(): void
    {
        ++$this->pageNumber;
        ++$this->currentPage;
    }

    /**
     * Get the current iterator key.
     */
    public function key(): int
    {
        return $this->currentPage;
    }

    /**
     * Determine if another page should be requested.
     */
    public function valid(): bool
    {
        if ($this->maxPages !== null && $this->currentPage >= $this->maxPages) {
            return false;
        }

        return $this->currentResponse === null || ! $this->isLastPage($this->currentResponse);
    }

    /**
     * Reset all iterator state.
     */
    public function rewind(): void
    {
        $this->pageNumber = $this->startPage;
        $this->currentPage = 0;
        $this->currentResponse = null;
        $this->totalResults = 0;
        $this->lastFiveBodyChecksums = [];
        $this->onRewind();
    }

    /**
     * Process extra state when the paginator rewinds.
     */
    protected function onRewind(): void
    {
    }

    /**
     * Iterate over every response item.
     *
     * @return iterable<array-key, mixed>
     */
    public function items(): iterable
    {
        foreach ($this as $response) {
            foreach ($this->pageItems($response) as $item) {
                yield $item;
            }
        }
    }

    /**
     * Create a lazy collection from page responses or response items.
     */
    public function collect(bool $throughItems = true): LazyCollection
    {
        return LazyCollection::make(function () use ($throughItems): iterable {
            return $throughItems ? yield from $this->items() : yield from $this;
        });
    }

    /**
     * Send every page through a bounded coroutine pool.
     *
     * @param null|callable(Response, int): void $responseHandler
     * @param null|callable(Throwable, int): void $exceptionHandler
     * @return array<int, Response>
     */
    public function pool(
        int $concurrency = 5,
        ?callable $responseHandler = null,
        ?callable $exceptionHandler = null,
    ): array {
        $this->rewind();

        if ($this->maxPages !== null && $this->maxPages <= 0) {
            return [];
        }

        $this->pooling = true;

        try {
            $firstKey = $this->key();
            $firstResponse = $this->current();
            $totalPages = $this->getTotalPages($firstResponse);
            $lastPage = $this->maxPages === null
                ? $totalPages
                : min($totalPages, $this->startPage + $this->maxPages - 1);
            $initialCallbackFailure = null;

            if ($responseHandler !== null) {
                try {
                    $responseHandler($firstResponse, $firstKey);
                } catch (Throwable $exception) {
                    $initialCallbackFailure = $exception;
                }
            }

            $remainingPool = $this->connector->pool(
                $this->remainingRequests($lastPage),
                $concurrency,
                $responseHandler,
                $exceptionHandler,
            );

            try {
                $responses = [$firstKey => $firstResponse] + $remainingPool->send();
            } catch (PoolException $exception) {
                throw new PoolException(
                    $exception->orchestrationFailure(),
                    $exception->failures(),
                    $initialCallbackFailure === null
                        ? $exception->callbackFailures()
                        : [$firstKey => $initialCallbackFailure] + $exception->callbackFailures(),
                    [$firstKey => $firstResponse] + $exception->responses(),
                );
            }

            if ($initialCallbackFailure !== null) {
                throw new PoolException(
                    null,
                    [],
                    [$firstKey => $initialCallbackFailure],
                    $responses,
                );
            }

            return $responses;
        } finally {
            $this->pooling = false;
        }
    }

    /**
     * Get the total number of processed results.
     */
    public function totalResults(): int
    {
        return $this->totalResults;
    }

    /**
     * Set the maximum number of pages to request.
     *
     * @return $this
     */
    public function maxPages(?int $maxPages): static
    {
        $this->maxPages = $maxPages;

        return $this;
    }

    /**
     * Set the number of results requested per page.
     *
     * @return $this
     */
    public function perPageLimit(?int $perPageLimit): static
    {
        $this->perPageLimit = $perPageLimit;

        return $this;
    }

    /**
     * Get the cloned request used by this paginator.
     */
    public function request(): Request
    {
        return $this->request;
    }

    /**
     * Get the current zero-based page position.
     */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Set the first page number requested after rewind.
     *
     * @return $this
     */
    public function startPage(int $startPage): static
    {
        $this->startPage = $startPage;

        return $this;
    }

    /**
     * Count the number of pages yielded by the iterator.
     */
    public function count(): int
    {
        // Counting performs one real request per page. iterator_count() would
        // advance without loading each response through current().
        $count = 0;

        foreach ($this as $ignored) {
            ++$count;
        }

        return $count;
    }

    /**
     * Resolve response items using the request override when present.
     *
     * @return array<array-key, mixed>
     */
    protected function pageItems(Response $response): array
    {
        $request = $response->request();

        return $request instanceof MapPaginatedResponseItems
            ? $request->mapPaginatedResponseItems($response)
            : $this->getPageItems($response, $request);
    }

    /**
     * Yield independently addressable page requests after the first page.
     *
     * @return iterable<int, Request>
     */
    protected function remainingRequests(int $lastPage): iterable
    {
        for ($page = $this->startPage + 1; $page <= $lastPage; ++$page) {
            $this->pageNumber = $page;

            // Build this request before yielding so a child cannot observe later generator state.
            yield $page - $this->startPage => $this->applyPagination(clone $this->request);
        }
    }

    /**
     * Get the total number of independently addressable pages.
     */
    protected function getTotalPages(Response $response): int
    {
        throw new LogicException('Implement [getTotalPages] to use pooled pagination.');
    }

    /**
     * Apply pagination to a cloned request.
     */
    abstract protected function applyPagination(Request $request): Request;

    /**
     * Determine if the response is the last page.
     */
    abstract protected function isLastPage(Response $response): bool;

    /**
     * Get the items from one page.
     *
     * @return array<array-key, mixed>
     */
    abstract protected function getPageItems(Response $response, Request $request): array;
}
