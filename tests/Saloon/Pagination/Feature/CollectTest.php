<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Feature;

use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\Response;
use Hypervel\Support\Collection;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors\PagedConnector;
use Hypervel\Tests\Saloon\Pagination\Fixtures\Requests\SuperheroPagedRequest;
use Hypervel\Tests\Saloon\Pagination\PaginationTestCase;

class CollectTest extends PaginationTestCase
{
    public function testCanCollectThroughPaginatedResponsesAndNotItems(): void
    {
        $connector = new PagedConnector;
        $request = new SuperheroPagedRequest;
        $paginator = $connector->paginate($request);

        $collection = $paginator->collect(throughItems: false)->collect();

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertContainsOnlyInstancesOf(Response::class, $collection);

        $toIds = static fn (array $items): array => array_map(static fn (array $item): int => $item['id'], $items);

        $this->assertSame([1, 2, 3, 4, 5], $toIds($collection[0]->json('data')));
        $this->assertSame([6, 7, 8, 9, 10], $toIds($collection[1]->json('data')));
        $this->assertSame([11, 12, 13, 14, 15], $toIds($collection[2]->json('data')));
        $this->assertSame([16, 17, 18, 19, 20], $toIds($collection[3]->json('data')));

        $this->assertSame(20, $paginator->totalResults());
    }

    /**
     * @see https://github.com/saloonphp/saloon/issues/464
     */
    public function testMultipleCollectCallsOnSameLazyCollectionDoNotTriggerInfiniteLoopDetection(): void
    {
        $singlePageResponse = [
            'data' => [['id' => 1], ['id' => 2]],
            'next_page_url' => null,
        ];

        Saloon::fake(new MockClient([
            MockResponse::make($singlePageResponse),
            MockResponse::make($singlePageResponse),
            MockResponse::make($singlePageResponse),
            MockResponse::make($singlePageResponse),
            MockResponse::make($singlePageResponse),
            MockResponse::make($singlePageResponse),
        ]));

        $connector = new PagedConnector;
        $request = new SuperheroPagedRequest;
        $lazyCollection = $connector->paginate($request)->collect();

        $first = $lazyCollection->collect();
        $this->assertInstanceOf(Collection::class, $first);
        $this->assertSame([1, 2], $first->pluck('id')->all());

        $lazyCollection->collect();
        $lazyCollection->collect();
        $lazyCollection->collect();
        $lazyCollection->collect();

        $again = $lazyCollection->collect();
        $this->assertSame([1, 2], $again->pluck('id')->all());
    }
}
