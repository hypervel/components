<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Feature;

use Hypervel\Saloon\Http\Response;
use Hypervel\Support\Collection;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\Async\OffsetConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\Async\PagedConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\PagedConnector as SyncPagedConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroLimitOffsetRequest;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroPagedRequest;
use Hypervel\Tests\Saloon\Pagination\PaginationTestCase;
use LogicException;

// REMOVED: async(), isAsyncPaginationEnabled() and iterating promises. Pages are sent concurrently in coroutines by
// pool(), which returns the responses keyed by page position, so these cases traverse through pool().
class AsyncPaginationTest extends PaginationTestCase
{
    public function testYouCanPaginateAsynchronouslyThroughManyPagesOfResultsWithPagedPagination(): void
    {
        $connector = new PagedConnector;
        $request = new SuperheroPagedRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];

        $iteratorCounter = 0;

        foreach ($paginator->pool() as $response) {
            ++$iteratorCounter;
            $superheroes = array_merge($superheroes, $response->json('data'));
        }

        $this->assertSame(4, $iteratorCounter);
        $this->assertSame(20, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(1, 20), $mapped);

        // Now we'll pool the same paginator again

        $collection = (new Collection($paginator->pool()))
            ->map(static fn (Response $response): array => $response->json('data'))
            ->collapse();

        $this->assertSame(range(1, 20), $collection->sortBy('id')->pluck('id')->values()->all());

        $this->assertSame(20, $paginator->totalResults());
    }

    public function testYouCanPaginateAsynchronouslyThroughManyPagesOfResultsWithLimitOffsetPagination(): void
    {
        $connector = new OffsetConnector;
        $request = new SuperheroLimitOffsetRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];

        $iteratorCounter = 0;

        foreach ($paginator->pool() as $response) {
            ++$iteratorCounter;
            $superheroes = array_merge($superheroes, $response->json('data'));
        }

        $this->assertSame(4, $iteratorCounter);
        $this->assertSame(20, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(1, 20), $mapped);

        // Now we'll pool the same paginator again

        $collection = (new Collection($paginator->pool()))
            ->map(static fn (Response $response): array => $response->json('data'))
            ->collapse();

        $this->assertSame(range(1, 20), $collection->sortBy('id')->pluck('id')->values()->all());

        $this->assertSame(20, $paginator->totalResults());
    }

    public function testIfYouDontImplementTheGetTotalPagesMethodOnAPaginatorItWillThrowAnExceptionIfYouTryToUseAsyncPagination(): void
    {
        $connector = new SyncPagedConnector;
        $request = new SuperheroPagedRequest;
        $paginator = $connector->paginate($request);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Implement [getTotalPages] to use pooled pagination.');

        $paginator->pool();
    }

    public function testIfThePaginatorReturnsAllThePagesInTheFirstPageItWontContinue(): void
    {
        $connector = new PagedConnector;
        $request = new SuperheroPagedRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];

        $iteratorCounter = 0;

        $paginator->perPageLimit(100);

        foreach ($paginator->pool() as $response) {
            ++$iteratorCounter;
            $superheroes = array_merge($superheroes, $response->json('data'));
        }

        $this->assertSame(1, $iteratorCounter);
        $this->assertSame(20, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(1, 20), $mapped);
    }
}
