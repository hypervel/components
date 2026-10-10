<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Feature;

use Hypervel\Saloon\Pagination\Contracts\Paginatable;
use Hypervel\Support\Collection;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\CursorConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\OffsetConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\PagedConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\MappedPagedRequest;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroCursorRequest;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroLimitOffsetRequest;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroPagedRequest;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\UserRequest;
use Hypervel\Tests\Saloon\Pagination\PaginationTestCase;
use InvalidArgumentException;

class PaginationTest extends PaginationTestCase
{
    public function testYouCanPaginateAutomaticallyThroughManyPagesOfResultsWithPagedPagination(): void
    {
        $connector = new PagedConnector;
        $request = new SuperheroPagedRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];
        $iteratorCounter = 0;

        foreach ($paginator as $item) {
            ++$iteratorCounter;
            $superheroes = array_merge($superheroes, $item->json('data'));
        }

        $this->assertSame(4, $iteratorCounter);
        $this->assertSame(20, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(1, 20), $mapped);

        // Now we'll test the collect method

        $collection = $paginator->collect()->collect();

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame(range(1, 20), $collection->pluck('id')->toArray());

        $this->assertSame(20, $paginator->totalResults());
    }

    public function testYouCanPaginateAutomaticallyThroughManyPagesOfResultsWithLimitOffsetPagination(): void
    {
        $connector = new OffsetConnector;
        $request = new SuperheroLimitOffsetRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];

        foreach ($paginator as $item) {
            $superheroes = array_merge($superheroes, $item->json('data'));
        }

        $this->assertSame(20, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(1, 20), $mapped);

        // Now we'll test the collect method

        $collection = $paginator->collect()->collect();

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame(range(1, 20), $collection->pluck('id')->toArray());

        $this->assertSame(20, $paginator->totalResults());
    }

    public function testYouCanPaginateAutomaticallyThroughManyPagesOfResultsWithCursorPagination(): void
    {
        $connector = new CursorConnector;
        $request = new SuperheroCursorRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];

        foreach ($paginator as $item) {
            $superheroes = array_merge($superheroes, $item->json('data'));
        }

        $this->assertSame(20, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(1, 20), $mapped);

        // Now we'll test the collect method

        $collection = $paginator->collect()->collect();

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame(range(1, 20), $collection->pluck('id')->toArray());

        $this->assertSame(20, $paginator->totalResults());
    }

    public function testYouCanSpecifyTheMaximumNumberOfPagesToIterateOver(): void
    {
        $connector = new CursorConnector;
        $request = new SuperheroCursorRequest;
        $paginator = $connector->paginate($request);

        $paginator->maxPages(2);

        $superheroes = [];
        $iteratorCounter = 0;

        foreach ($paginator as $item) {
            ++$iteratorCounter;
            $superheroes = array_merge($superheroes, $item->json('data'));
        }

        $this->assertSame(2, $iteratorCounter);
        $this->assertCount(10, $superheroes);

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(1, 10), $mapped);
    }

    public function testIfThePaginatorReturnsAllThePagesInTheFirstPageItWontContinue(): void
    {
        $connector = new PagedConnector;
        $request = new SuperheroPagedRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];
        $iteratorCounter = 0;

        $paginator->perPageLimit(100);

        foreach ($paginator as $response) {
            ++$iteratorCounter;
            $superheroes = array_merge($superheroes, $response->json('data'));
        }

        $this->assertSame(1, $iteratorCounter);
        $this->assertSame(20, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(1, 20), $mapped);
    }

    public function testThePaginatorWillThrowAnExceptionIfYouUseARequestThatIsNotPaginatable(): void
    {
        $connector = new PagedConnector;
        $request = new UserRequest;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('The request must implement [' . Paginatable::class . '] to be used with a paginator.');

        $connector->paginate($request);
    }

    public function testAnIndividualRequestCanImplementTheGetPaginatedResultsInterfaceToOverwriteTheConnectorsPaginator(): void
    {
        $connector = new PagedConnector;
        $request = new MappedPagedRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];

        foreach ($paginator->items() as $item) {
            $superheroes[] = $item;
        }

        $this->assertSame([
            'Batman',
            'Superman',
            'Flash',
            'Green Lantern',
            'Green Arrow',
            'Wonder Woman',
            'Martian Manhunter',
            'Robin/Nightwing',
            'Blue Beetle',
            'Black Canary',
            'Spider Man',
            'Captain America',
            'Iron Man',
            'Thor',
            'Hulk',
            'Wolverine',
            'Daredevil',
            'Hawkeye',
            'Cyclops',
            'Silver Surfer',
        ], $superheroes);
    }
}
