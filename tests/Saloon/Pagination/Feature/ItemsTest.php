<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Feature;

use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\PagedConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroPagedRequest;
use Hypervel\Tests\Saloon\Pagination\PaginationTestCase;

class ItemsTest extends PaginationTestCase
{
    public function testYouCanIterateThroughTheItemsOfAPaginatedResource(): void
    {
        $connector = new PagedConnector;
        $request = new SuperheroPagedRequest;
        $paginator = $connector->paginate($request);

        $superheroes = [];
        $iteratorCounter = 0;

        foreach ($paginator->items() as $item) {
            $superheroes[] = $item;
            ++$iteratorCounter;
        }

        $this->assertSame(20, $iteratorCounter);

        $mapped = array_map(static fn (array $superhero): int => $superhero['id'], $superheroes);

        $this->assertSame(range(1, 20), $mapped);
    }
}
