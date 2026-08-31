<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\Sender;
use Hypervel\Saloon\Pagination\Contracts\MapPaginatedResponseItems;
use Hypervel\Saloon\Pagination\Contracts\Paginatable;
use Hypervel\Saloon\Pagination\CursorPaginator;
use Hypervel\Saloon\Pagination\Exceptions\PaginationException;
use Hypervel\Saloon\Pagination\OffsetPaginator;
use Hypervel\Saloon\Pagination\PagedPaginator;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;

class PaginatorTest extends TestCase
{
    public function testPagedPaginatorIteratesItemsAndResetsEveryStateOnRewind(): void
    {
        $manager = $this->manager();
        $manager->fake([
            PagedRequestStub::class => static function (PendingRequest $pendingRequest): MockResponse {
                $page = (int) $pendingRequest->request()->queryParameters()['page'];

                return MockResponse::make([
                    'data' => [($page * 2) - 1, $page * 2],
                    'page' => $page,
                    'pages' => 3,
                ]);
            },
        ]);
        $request = (new PagedRequestStub)->withHeader('X-Paginated', 'yes');
        $paginator = new PagedPaginatorStub(new PaginationConnectorStub($manager), $request);

        $this->assertSame([1, 2, 3, 4, 5, 6], iterator_to_array($paginator->items(), false));
        $this->assertSame(6, $paginator->totalResults());
        $this->assertSame([1, 2, 3, 4, 5, 6], $paginator->collect()->all());
        $this->assertSame(6, $paginator->totalResults());
        $this->assertSame(['X-Paginated' => 'yes'], $paginator->request()->headers());
        $this->assertSame([], $request->queryParameters());
    }

    public function testStartPageMaximumPagesAndCountUsePageSemantics(): void
    {
        $manager = $this->manager();
        $manager->fake([
            PagedRequestStub::class => static function (PendingRequest $pendingRequest): MockResponse {
                $page = (int) $pendingRequest->request()->queryParameters()['page'];

                return MockResponse::make(['data' => [$page], 'page' => $page, 'pages' => 5]);
            },
        ]);
        $paginator = (new PagedPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub))
            ->startPage(3)
            ->maxPages(2);

        $responses = iterator_to_array($paginator);

        $this->assertSame([0, 1], array_keys($responses));
        $this->assertSame([3, 4], array_map(
            static fn (Response $response): int => (int) $response->json('page'),
            array_values($responses),
        ));
        $this->assertSame(2, count($paginator));
    }

    #[DataProvider('startPageProvider')]
    public function testIteratorAndPoolPositionsAreIndependentOfTheRemoteStartPage(int $startPage): void
    {
        $requestedPages = [];
        $manager = $this->manager();
        $manager->fake([
            PagedRequestStub::class => static function (PendingRequest $pendingRequest) use (&$requestedPages): MockResponse {
                $page = (int) $pendingRequest->request()->queryParameters()['page'];
                $requestedPages[] = $page;

                return MockResponse::make(['data' => [$page], 'page' => $page, 'pages' => 10]);
            },
        ]);
        $paginator = (new PagedPaginatorStub(
            new PaginationConnectorStub($manager),
            new PagedRequestStub,
        ))->startPage($startPage)->maxPages(2);

        $responses = iterator_to_array($paginator);
        $pooledResponses = $paginator->pool();
        $repeatedResponses = iterator_to_array($paginator);

        $expectedPages = [$startPage, $startPage + 1];
        $this->assertSame([0, 1], array_keys($responses));
        $this->assertSame($expectedPages, array_map(
            static fn (Response $response): int => (int) $response->json('page'),
            array_values($responses),
        ));
        $this->assertSame([0, 1], array_keys($pooledResponses));
        $this->assertSame($expectedPages, array_map(
            static fn (Response $response): int => (int) $response->json('page'),
            array_values($pooledResponses),
        ));
        $this->assertSame([0, 1], array_keys($repeatedResponses));
        $this->assertSame([...$expectedPages, ...$expectedPages, ...$expectedPages], $requestedPages);
    }

    /**
     * Provide remote start pages.
     */
    public static function startPageProvider(): iterable
    {
        yield 'zero' => [0];
        yield 'one' => [1];
        yield 'five' => [5];
    }

    #[DataProvider('pageLimitProvider')]
    public function testPageLimitsApplyEquallyToIterationAndPooling(int $maxPages, array $expectedPages): void
    {
        $requestCount = 0;
        $manager = $this->manager();
        $manager->fake([
            PagedRequestStub::class => static function (PendingRequest $pendingRequest) use (&$requestCount): MockResponse {
                ++$requestCount;
                $page = (int) $pendingRequest->request()->queryParameters()['page'];

                return MockResponse::make(['data' => [$page], 'page' => $page, 'pages' => 5]);
            },
        ]);
        $paginator = (new PagedPaginatorStub(
            new PaginationConnectorStub($manager),
            new PagedRequestStub,
        ))->startPage(0)->maxPages($maxPages);

        $responses = iterator_to_array($paginator);
        $pooledResponses = $paginator->pool();

        $this->assertSame(array_keys($expectedPages), array_keys($responses));
        $this->assertSame($expectedPages, array_map(
            static fn (Response $response): int => (int) $response->json('page'),
            array_values($responses),
        ));
        $this->assertSame(array_keys($expectedPages), array_keys($pooledResponses));
        $this->assertSame($expectedPages, array_map(
            static fn (Response $response): int => (int) $response->json('page'),
            array_values($pooledResponses),
        ));
        $this->assertSame(count($expectedPages) * 2, $requestCount);
    }

    /**
     * Provide page limits and their expected remote pages.
     */
    public static function pageLimitProvider(): iterable
    {
        yield 'negative' => [-1, []];
        yield 'zero' => [0, []];
        yield 'one' => [1, [0]];
        yield 'multiple' => [3, [0, 1, 2]];
    }

    public function testOffsetAndCursorPaginatorsUseTheirExpectedParameters(): void
    {
        $offsets = [];
        $cursors = [];
        $manager = $this->manager();
        $manager->fake([
            OffsetRequestStub::class => static function (PendingRequest $pendingRequest) use (&$offsets): MockResponse {
                $query = $pendingRequest->request()->queryParameters();
                $offsets[] = $query;

                return MockResponse::make([
                    'data' => [(int) $query['offset'] + 1, (int) $query['offset'] + 2],
                    'offset' => $query['offset'],
                    'total' => 4,
                ]);
            },
            CursorRequestStub::class => static function (PendingRequest $pendingRequest) use (&$cursors): MockResponse {
                $cursor = $pendingRequest->request()->queryParameters()['cursor'] ?? null;
                $cursors[] = $cursor;

                return MockResponse::make($cursor === null
                    ? ['data' => [1, 2], 'next' => 'cursor-2']
                    : ['data' => [3, 4], 'next' => null]);
            },
        ]);
        $connector = new PaginationConnectorStub($manager);
        $offset = (new OffsetPaginatorStub($connector, new OffsetRequestStub))->perPageLimit(2);
        $cursor = (new CursorPaginatorStub($connector, new CursorRequestStub))->perPageLimit(2);

        $this->assertSame([1, 2, 3, 4], iterator_to_array($offset->items(), false));
        $this->assertSame([['limit' => 2, 'offset' => 0], ['limit' => 2, 'offset' => 2]], $offsets);
        $this->assertSame([1, 2, 3, 4], iterator_to_array($cursor->items(), false));
        $this->assertSame([null, 'cursor-2'], $cursors);
    }

    public function testRequestCanMapPaginatedItems(): void
    {
        $manager = $this->manager();
        $manager->fake([
            MappedPagedRequestStub::class => static function (PendingRequest $pendingRequest): MockResponse {
                $page = (int) $pendingRequest->request()->queryParameters()['page'];

                return MockResponse::make([
                    'data' => [['name' => 'item-' . $page]],
                    'page' => $page,
                    'pages' => 1,
                ]);
            },
        ]);
        $paginator = new PagedPaginatorStub(
            new PaginationConnectorStub($manager),
            new MappedPagedRequestStub,
        );

        $this->assertSame(['item-1'], iterator_to_array($paginator->items(), false));
    }

    public function testRepeatedBodiesStopASequentialPaginationLoop(): void
    {
        $manager = $this->manager();
        $manager->fake([PagedRequestStub::class => MockResponse::make(['data' => [1]])]);
        $paginator = (new NeverEndingPagedPaginatorStub(
            new PaginationConnectorStub($manager),
            new PagedRequestStub,
        ))->maxPages(6);

        $this->expectException(PaginationException::class);

        iterator_to_array($paginator);
    }

    public function testPooledPaginationFetchesTheFirstPageThenBoundsRemainingWork(): void
    {
        $active = 0;
        $maximumActive = 0;
        $manager = $this->manager();
        $manager->fake([
            PagedRequestStub::class => static function (PendingRequest $pendingRequest) use (&$active, &$maximumActive): MockResponse {
                $page = (int) $pendingRequest->request()->queryParameters()['page'];
                ++$active;
                $maximumActive = max($maximumActive, $active);
                usleep((5 - $page) * 1000);
                --$active;

                return MockResponse::make(['data' => [$page], 'page' => $page, 'pages' => 5]);
            },
        ]);
        $handled = [];
        $paginator = (new PagedPaginatorStub(
            new PaginationConnectorStub($manager),
            new PagedRequestStub,
        ))->maxPages(4);

        $responses = $paginator->pool(
            concurrency: 2,
            responseHandler: static function (Response $response, int $key) use (&$handled): void {
                $handled[$key] = $response->json('page');
            },
        );

        ksort($handled);
        $this->assertSame([0, 1, 2, 3], array_keys($responses));
        $this->assertSame([1, 2, 3, 4], array_values($handled));
        $this->assertSame(2, $maximumActive);
        $this->assertSame(4, $paginator->totalResults());
    }

    public function testCursorPaginationCannotBePooledAndOffsetRequiresALimit(): void
    {
        $connector = new PaginationConnectorStub($this->manager());

        try {
            (new CursorPaginatorStub($connector, new CursorRequestStub))->pool();
            $this->fail('Cursor pagination was pooled.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);

        (new OffsetPaginatorStub($connector, new OffsetRequestStub))->current();
    }

    public function testPaginatorRequiresAPaginatableRequest(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PagedPaginatorStub(new PaginationConnectorStub($this->manager()), new NonPaginatableRequestStub);
    }

    /**
     * Create a Saloon manager.
     */
    protected function manager(): SaloonManager
    {
        $http = new Factory;
        $http->registerConnection('saloon');
        $config = m::mock(ConfigRepository::class);
        $config->shouldReceive('string')->with('saloon.connection.name')->andReturn('saloon');

        return new SaloonManager(
            new Sender($http, $config),
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
            $config,
            new Dispatcher,
        );
    }
}

class PaginationConnectorStub extends Connector
{
    public function __construct(protected SaloonManager $manager)
    {
    }

    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    public function send(Request $request, ?MockClient $mockClient = null): Response
    {
        return $this->manager->send($this, $request, $mockClient);
    }
}

class PagedRequestStub extends Request implements Paginatable
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/paged';
    }
}

class MappedPagedRequestStub extends PagedRequestStub implements MapPaginatedResponseItems
{
    public function mapPaginatedResponseItems(Response $response): array
    {
        return array_column($response->json('data'), 'name');
    }
}

class OffsetRequestStub extends Request implements Paginatable
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/offset';
    }
}

class CursorRequestStub extends Request implements Paginatable
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/cursor';
    }
}

class NonPaginatableRequestStub extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/invalid';
    }
}

class PagedPaginatorStub extends PagedPaginator
{
    protected function isLastPage(Response $response): bool
    {
        return $response->json('page') >= $response->json('pages');
    }

    protected function getPageItems(Response $response, Request $request): array
    {
        return $response->json('data');
    }

    protected function getTotalPages(Response $response): int
    {
        return (int) $response->json('pages');
    }
}

class NeverEndingPagedPaginatorStub extends PagedPaginator
{
    protected function isLastPage(Response $response): bool
    {
        return false;
    }

    protected function getPageItems(Response $response, Request $request): array
    {
        return $response->json('data');
    }
}

class OffsetPaginatorStub extends OffsetPaginator
{
    protected function isLastPage(Response $response): bool
    {
        return $response->json('offset') + count($response->json('data')) >= $response->json('total');
    }

    protected function getPageItems(Response $response, Request $request): array
    {
        return $response->json('data');
    }

    protected function getTotalPages(Response $response): int
    {
        return (int) ceil($response->json('total') / $this->perPageLimit);
    }
}

class CursorPaginatorStub extends CursorPaginator
{
    protected function getNextCursor(Response $response): int|string
    {
        return $response->json('next');
    }

    protected function isLastPage(Response $response): bool
    {
        return $response->json('next') === null;
    }

    protected function getPageItems(Response $response, Request $request): array
    {
        return $response->json('data');
    }
}
