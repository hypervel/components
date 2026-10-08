<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Feature;

use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\CursorConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\OffsetConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\PagedConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroCursorRequest;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroLimitOffsetRequest;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroPagedRequest;
use Hypervel\Tests\Saloon\Pagination\PaginationTestCase;

class PerPageLimitTest extends PaginationTestCase
{
    public function testYouCanSpecifyAPerPageLimitOnAPagedPaginator(): void
    {
        $connector = new PagedConnector;
        $request = new SuperheroPagedRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];
        $iteratorCounter = 0;

        $paginator->perPageLimit(10);

        foreach ($paginator as $item) {
            ++$iteratorCounter;
            $superheroes = array_merge($superheroes, $item->json('data'));
        }

        $this->assertSame(2, $iteratorCounter);
        $this->assertSame(20, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(1, 20), $mapped);
    }

    public function testYouCanSpecifyAPerPageLimitOnALimitOffsetPaginator(): void
    {
        $connector = new OffsetConnector;
        $request = new SuperheroLimitOffsetRequest;
        $paginator = $connector->paginate($request);

        $paginator->perPageLimit(10);

        $superheroes = [];
        $iteratorCounter = 0;

        foreach ($paginator as $item) {
            ++$iteratorCounter;
            $superheroes = array_merge($superheroes, $item->json('data'));
        }

        $this->assertSame(2, $iteratorCounter);
        $this->assertSame(20, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(1, 20), $mapped);
    }

    public function testYouCanSpecifyAPerPageLimitOnACursorPaginator(): void
    {
        $connector = new CursorConnector;
        $request = new SuperheroCursorRequest;
        $paginator = $connector->paginate($request);

        $paginator->perPageLimit(10);

        $superheroes = [];
        $iteratorCounter = 0;

        foreach ($paginator as $item) {
            ++$iteratorCounter;
            $superheroes = array_merge($superheroes, $item->json('data'));
        }

        $this->assertSame(2, $iteratorCounter);
        $this->assertSame(20, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(1, 20), $mapped);
    }
}
