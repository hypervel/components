<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Feature;

use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\CustomStartPagePagedConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\PagedConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroPagedRequest;
use Hypervel\Tests\Saloon\Pagination\PaginationTestCase;

class StartPageTest extends PaginationTestCase
{
    public function testYouCanSpecifyAStartPageOnAPaginatorClass(): void
    {
        $connector = new CustomStartPagePagedConnector;
        $request = new SuperheroPagedRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];
        $iteratorCounter = 0;

        foreach ($paginator as $item) {
            ++$iteratorCounter;
            $superheroes = array_merge($superheroes, $item->json('data'));
        }

        $this->assertSame(2, $iteratorCounter);
        $this->assertSame(10, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(11, 20), $mapped);
    }

    public function testYouCanSpecifyAStartPageOnAPaginatorInstance(): void
    {
        $connector = new PagedConnector;
        $request = new SuperheroPagedRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];
        $iteratorCounter = 0;

        $paginator->startPage(3);

        foreach ($paginator as $item) {
            ++$iteratorCounter;
            $superheroes = array_merge($superheroes, $item->json('data'));
        }

        $this->assertSame(2, $iteratorCounter);
        $this->assertSame(10, $paginator->totalResults());

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(11, 20), $mapped);
    }
}
