<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Feature;

use Hypervel\Saloon\Http\Response;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\Async\OffsetConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\Async\PagedConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroLimitOffsetRequest;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroPagedRequest;
use Hypervel\Tests\Saloon\Pagination\PaginationTestCase;

// pool() sends the pages and returns their responses, in place of upstream's pool()->send()->wait().
class AsyncPoolPaginationTest extends PaginationTestCase
{
    public function testYouCanMakeAPoolOfRequestsWithPagedPagination(): void
    {
        $connector = new PagedConnector;
        $request = new SuperheroPagedRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];
        $iteratorCounter = 0;

        $paginator->pool(5, function (Response $response) use (&$iteratorCounter, &$superheroes): void {
            ++$iteratorCounter;
            $superheroes = array_merge($superheroes, $response->json('data'));
        });

        $this->assertSame(4, $iteratorCounter);
        $this->assertSame(20, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        sort($mapped);

        $this->assertSame(range(1, 20), $mapped);
    }

    public function testYouCanMakeAPoolOfRequestsWithLimitOffsetPagination(): void
    {
        $connector = new OffsetConnector;
        $request = new SuperheroLimitOffsetRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];
        $iteratorCounter = 0;

        $paginator->pool(5, function (Response $response) use (&$iteratorCounter, &$superheroes): void {
            ++$iteratorCounter;
            $superheroes = array_merge($superheroes, $response->json('data'));
        });

        $this->assertSame(4, $iteratorCounter);
        $this->assertSame(20, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        sort($mapped);

        $this->assertSame(range(1, 20), $mapped);
    }
}
