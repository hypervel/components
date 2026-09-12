<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination;

use Closure;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Exceptions\PoolException;
use Hypervel\Saloon\Http\Auth\QueryAuthenticator;
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
use Hypervel\Saloon\Pagination\LinkHeaderPaginator;
use Hypervel\Saloon\Pagination\OffsetPaginator;
use Hypervel\Saloon\Pagination\PagedPaginator;
use Hypervel\Saloon\SaloonManager;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;
use Throwable;
use WeakReference;

class PaginatorTest extends TestCase
{
    #[DataProvider('currentPageLoads')]
    public function testCurrentLoadsEachPageOnceAndRetriesFailedMapping(string $class, array $queries, bool $failMapping): void
    {
        $mappingCalls = 0;
        $failure = new RuntimeException('Cannot map the first page.');
        $request = new class(static function (Response $response) use (&$mappingCalls, $failMapping, $failure): array {
            if (++$mappingCalls === 1 && $failMapping) {
                throw $failure;
            }

            return $response->json('data');
        }) extends PagedRequestStub implements MapPaginatedResponseItems {
            /**
             * Share mapping observations across request clones.
             */
            public function __construct(public Closure $mapper)
            {
            }

            /**
             * Map the page through the test callback.
             */
            public function mapPaginatedResponseItems(Response $response): array
            {
                return ($this->mapper)($response);
            }
        };
        $requestedQueries = [];
        $manager = $this->manager();
        $manager->fake([$request::class => static function (PendingRequest $pendingRequest) use (&$requestedQueries, $queries): MockResponse {
            $query = $pendingRequest->uri()->getQuery();
            $requestedQueries[] = $query;
            $page = $query === $queries[0] ? 1 : 2;

            return MockResponse::make([
                'data' => [$page], 'page' => $page, 'pages' => 2, 'next' => $page === 1 ? '2' : null,
            ], headers: $page === 1 ? ['Link' => '<?page=2>; rel=next'] : []);
        }]);
        $paginator = new $class(new PaginationConnectorStub($manager), $request);

        if ($failMapping) {
            $caught = null;
            try {
                $paginator->current();
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }
            $this->assertSame($failure, $caught);
            $this->assertSame(0, $paginator->totalResults());
        }

        $first = $paginator->current();
        $this->assertSame($first, $paginator->current());
        $this->assertSame(0, $paginator->key());
        $this->assertSame(1, $paginator->totalResults());
        $this->assertSame($failMapping ? 2 : 1, $mappingCalls);
        $this->assertSame($failMapping ? [$queries[0], $queries[0]] : [$queries[0]], $requestedQueries);

        $paginator->next();
        $second = $paginator->current();
        $this->assertSame($second, $paginator->current());
        $this->assertSame([2], $second->json('data'));
        $this->assertSame(1, $paginator->key());
        $this->assertSame(2, $paginator->totalResults());
        $this->assertSame([1, 2], iterator_to_array($paginator->items(), false));
        $this->assertSame(2, $paginator->totalResults());
        $this->assertSame($failMapping ? 5 : 4, $mappingCalls);
        $this->assertSame($failMapping ? [$queries[0], ...$queries, ...$queries] : [...$queries, ...$queries], $requestedQueries);
    }

    /**
     * Provide paginator strategies with successful and failed first-page mapping.
     */
    public static function currentPageLoads(): iterable
    {
        foreach ([
            'paged' => [PagedPaginatorStub::class, ['page=1', 'page=2']],
            'cursor' => [CursorPaginatorStub::class, ['', 'cursor=2']],
            'link' => [LinkPaginatorStub::class, ['page=1', 'page=2']],
        ] as $name => [$class, $queries]) {
            yield $name => [$class, $queries, false];
            yield $name . ' mapping retry' => [$class, $queries, true];
        }
    }

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
        $this->assertSame(1, $paginator->request()->mapping->calls);
    }

    #[DataProvider('responseChanges')]
    public function testItemsAreMappedOnceAfterTheCompleteResponsePipeline(bool $replace): void
    {
        $manager = $this->manager();
        $manager->fake([PagedRequestStub::class => MockResponse::make([
            'data' => ['original'], 'page' => 1, 'pages' => 1,
        ])]);
        $manager->middleware()->onResponse(static function (Response $response) use ($replace): Response {
            if ($replace) {
                $response = Response::fromResponse($response, $response->pendingRequest(), $response->toPsrRequest());
            }

            return $response->decodeUsing(static fn (): array => [
                'data' => ['first', 'second'], 'page' => 1, 'pages' => 1,
            ]);
        });
        $paginator = new MappingPagedPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub);

        $this->assertSame(['first', 'second'], $paginator->collect()->all());
        $this->assertSame([1], $paginator->mappedPages);
        $this->assertSame(2, $paginator->totalResults());
    }

    /**
     * Provide supported response middleware changes.
     */
    public static function responseChanges(): array
    {
        return ['in place' => [false], 'replacement' => [true]];
    }

    public function testMappedItemsAreReleasedOnAdvanceAndRewind(): void
    {
        $manager = $this->manager();
        $manager->fake([PagedRequestStub::class => MockResponse::make([
            'data' => [1], 'page' => 1, 'pages' => 1,
        ])]);
        $paginator = new MappingPagedPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub);
        $references = [];
        $paginator->mapItems = static function () use (&$references): array {
            $item = (object) ['id' => 1];
            $references[] = WeakReference::create($item);

            return [$item];
        };

        $paginator->current();
        $this->assertNotNull($references[0]->get());
        $paginator->next();
        $this->assertNull($references[0]->get());
        $paginator->current();
        $this->assertNotNull($references[1]->get());
        $paginator->rewind();
        $this->assertNull($references[1]->get());
        $this->assertSame(0, $paginator->totalResults());
    }

    public function testPooledMappingReleasesItemsBeforePageCountsAndCallbacks(): void
    {
        $manager = $this->manager();
        $manager->fake([PagedRequestStub::class => static function (PendingRequest $pendingRequest): MockResponse {
            $page = $pendingRequest->queryParameters()['page'];

            return MockResponse::make(['data' => [$page], 'page' => $page, 'pages' => 3]);
        }]);
        $paginator = new MappingPagedPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub);
        $references = [];
        $paginator->mapItems = static function (Response $response) use (&$references): array {
            usleep(1000);
            $item = (object) ['id' => $response->json('page')];
            $references[] = WeakReference::create($item);

            return [$item];
        };
        $paginator->resolvePages = function () use (&$references): int {
            $this->assertNull($references[0]->get());

            return 3;
        };
        $handled = [];

        $paginator->pool(responseHandler: function (Response $response, int $key) use (&$references, &$handled): void {
            foreach ($references as $reference) {
                $this->assertNull($reference->get());
            }
            $handled[] = $key;
        });

        sort($handled);
        $this->assertSame([0, 1, 2], $handled);
        sort($paginator->mappedPages);
        $this->assertSame([1, 2, 3], $paginator->mappedPages);
        $this->assertSame(3, $paginator->totalResults());
    }

    public function testFirstPageMappingFailureStopsBeforeSchedulingThePool(): void
    {
        $manager = $this->manager();
        $requested = 0;
        $manager->fake([PagedRequestStub::class => static function () use (&$requested): MockResponse {
            ++$requested;

            return MockResponse::make(['data' => [1], 'page' => 1, 'pages' => 3]);
        }]);
        $paginator = new MappingPagedPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub);
        $failure = new RuntimeException('Cannot map the first page.');
        $paginator->mapItems = static fn (): never => throw $failure;
        $handled = [];
        $caught = null;

        try {
            $paginator->pool(responseHandler: static function (Response $response, int $key) use (&$handled): void {
                $handled[] = $key;
            });
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertSame($failure, $caught);
        $this->assertSame(1, $requested);
        $this->assertSame([], $handled);
        $this->assertSame(0, $paginator->totalResults());
    }

    public function testFirstPageHandlerCancellationStopsBeforeSchedulingRemainingPages(): void
    {
        $manager = $this->manager();
        $requested = [];
        $manager->fake([PagedRequestStub::class => static function (PendingRequest $pendingRequest) use (&$requested): MockResponse {
            $page = $pendingRequest->queryParameters()['page'];
            $requested[] = $page;

            return MockResponse::make(['data' => [$page], 'page' => $page, 'pages' => 3]);
        }]);
        $paginator = new PagedPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub);
        $started = new Channel(1);
        $blocker = new Channel(1);
        $nativeCancellation = null;
        $outcome = null;
        $runner = EngineCoroutine::create(static function () use ($paginator, $started, $blocker, &$nativeCancellation, &$outcome): void {
            try {
                $paginator->pool(responseHandler: static function () use ($started, $blocker, &$nativeCancellation): void {
                    $started->push(true);

                    try {
                        $blocker->pop(5);
                    } catch (CanceledException $exception) {
                        $nativeCancellation = $exception;
                        throw $exception;
                    }
                });
            } catch (Throwable $exception) {
                $outcome = $exception;
            }
        });

        try {
            $this->assertTrue($started->pop(5));
            $this->assertTrue(EngineCoroutine::cancelById($runner->getId(), throwException: true));
            EngineCoroutine::join([$runner->getId()], 5);
            $this->assertFalse(EngineCoroutine::exists($runner->getId()));
            $this->assertInstanceOf(CanceledException::class, $nativeCancellation);
            $this->assertSame($nativeCancellation, $outcome);
            $this->assertSame([1], $requested);
        } finally {
            if (EngineCoroutine::exists($runner->getId())) {
                EngineCoroutine::cancelById($runner->getId(), throwException: true);
                EngineCoroutine::join([$runner->getId()], 5);
            }

            $started->close();
            $blocker->close();
        }
    }

    #[DataProvider('poolCallbackFailures')]
    public function testPoolCountsMappedPagesEvenWhenCallerHandlersFail(bool $mapperFails, int $failedKey): void
    {
        $manager = $this->manager();
        $manager->fake([PagedRequestStub::class => static function (PendingRequest $pendingRequest): MockResponse {
            $page = $pendingRequest->queryParameters()['page'];

            return MockResponse::make(['data' => [$page], 'page' => $page, 'pages' => 3]);
        }]);
        $paginator = new MappingPagedPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub);
        $failure = new RuntimeException('Page callback failed.');
        $paginator->mapItems = static function (Response $response) use ($mapperFails, $failedKey, $failure): array {
            if ($mapperFails && $response->json('page') === $failedKey + 1) {
                throw $failure;
            }

            return $response->json('data');
        };
        $handled = [];
        $caught = null;

        try {
            $paginator->pool(responseHandler: static function (Response $response, int $key) use (&$handled, $mapperFails, $failedKey, $failure): void {
                $handled[] = $key;
                if (! $mapperFails && $key === $failedKey) {
                    throw $failure;
                }
            });
        } catch (PoolException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(PoolException::class, $caught);
        $this->assertSame([$failedKey => $failure], $caught->callbackFailures());
        $this->assertSame([], $caught->failures());
        $this->assertCount(3, $caught->responses());
        $this->assertSame($mapperFails ? 2 : 3, $paginator->totalResults());
        sort($handled);
        $this->assertSame($mapperFails ? [0, 2] : [0, 1, 2], $handled);
        sort($paginator->mappedPages);
        $this->assertSame([1, 2, 3], $paginator->mappedPages);
    }

    /**
     * Provide mapper and caller-handler failure positions.
     */
    public static function poolCallbackFailures(): array
    {
        return ['mapper' => [true, 1], 'first handler' => [false, 0], 'remaining handler' => [false, 1]];
    }

    #[DataProvider('renamedParameters')]
    public function testPaginationQueryNamesCanBeConfigured(string $class, array $expected): void
    {
        $manager = $this->manager();
        $queries = [];
        $manager->fake([PagedRequestStub::class => static function (PendingRequest $pendingRequest) use (&$queries): MockResponse {
            $queries[] = $pendingRequest->queryParameters();

            return MockResponse::make(['data' => [count($queries)], 'next' => 'next-token']);
        }]);
        $paginator = (new $class(new PaginationConnectorStub($manager), new PagedRequestStub))
            ->perPageLimit(2)->maxPages(2);

        iterator_to_array($paginator);

        $this->assertSame($expected, $queries);
    }

    /**
     * Provide each configurable query parameter pair.
     */
    public static function renamedParameters(): array
    {
        return [
            [RenamedPagedPaginatorStub::class, [['number' => 1, 'size' => 2], ['number' => 2, 'size' => 2]]],
            [RenamedOffsetPaginatorStub::class, [['take' => 2, 'skip' => 0], ['take' => 2, 'skip' => 2]]],
            [RenamedCursorPaginatorStub::class, [['size' => 2], ['after' => 'next-token', 'size' => 2]]],
        ];
    }

    public function testLinkContinuationReplacesRawQueryAndPageSizeAndResetsOnRewind(): void
    {
        $manager = $this->manager();
        $queries = [];
        $manager->fake([PagedRequestStub::class => static function (PendingRequest $pendingRequest) use (&$queries): MockResponse {
            $query = $pendingRequest->uri()->getQuery();
            $queries[] = $query;
            $first = ! str_contains($query, 'cursor=');

            return MockResponse::make(['data' => [$first ? 1 : 2]], headers: $first ? [
                'Link' => '<?cursor=a%2Fb&tag=one&tag=two&size=7>; rel=next',
            ] : []);
        }]);
        $request = (new PagedRequestStub)->withQueryString('old=value')
            ->withQueryParameters(['filter' => 'active'])
            ->authenticate(new QueryAuthenticator('key', 'secret'));
        $paginator = (new RenamedLinkPaginatorStub(new PaginationConnectorStub($manager), $request))->perPageLimit(2);

        $this->assertSame([1, 2], $paginator->collect()->all());
        $this->assertSame([1, 2], $paginator->collect()->all());
        $this->assertSame([
            'old=value&filter=active&number=1&size=2&key=secret',
            'cursor=a%2Fb&tag=one&tag=two&size=7&filter=active&key=secret',
            'old=value&filter=active&number=1&size=2&key=secret',
            'cursor=a%2Fb&tag=one&tag=two&size=7&filter=active&key=secret',
        ], $queries);
        $this->assertSame('old=value', $request->queryString());
    }

    #[DataProvider('validLinkHeaders')]
    public function testLinkHeadersNavigateOnlyEffectivePaginationRelations(array $headers, ?string $nextQuery): void
    {
        $manager = $this->manager();
        $queries = [];
        $manager->fake([PagedRequestStub::class => static function (PendingRequest $pendingRequest) use (&$queries, $headers): MockResponse {
            $queries[] = $pendingRequest->uri()->getQuery();

            return MockResponse::make(['data' => [count($queries)]], headers: count($queries) === 1 ? ['Link' => $headers] : []);
        }]);
        $paginator = new LinkPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub);

        $this->assertSame($nextQuery === null ? [1] : [1, 2], $paginator->collect()->all());
        $this->assertSame($nextQuery === null ? ['page=1'] : ['page=1', $nextQuery], $queries);
    }

    /**
     * Provide valid HTTP list syntax and effective relation combinations.
     */
    public static function validLinkHeaders(): array
    {
        return [
            'no header' => [[], null],
            'last only' => [['<?page=1>; rel=last'], null],
            'relative path' => [['<paged?page=2>; rel=next'], 'page=2'],
            'root relative' => [['</paged?page=2>; rel=next'], 'page=2'],
            'case and default port' => [['<HTTPS://API.EXAMPLE.COM:443/paged?page=2>; REL=NEXT'], 'page=2'],
            'several fields' => [['<?page=2>; rel=next', '<?page=3>; rel=last'], 'page=2'],
            'first rel wins' => [['<?page=2>; rel=next; rel=last'], 'page=2'],
            'unquoted media type' => [['<?page=2>; rel=next; type=application/json'], 'page=2'],
            'multiple relations' => [['<?page=2>; rel="next last"'], 'page=2'],
            'equivalent duplicate targets' => [['<?page=2>; rel=next, <https://api.example.com/paged?page=2>; rel=next'], 'page=2'],
            'anchored and ordinary links' => [['<?page=9>; rel=next; anchor="/another", <?page=2>; rel=next'], 'page=2'],
            'unknown relations and absent values' => [['<?page=8>, <?page=7>; rel=, <?page=6>; rel="", <?page=5>; rel=prev, <?page=4>; rel=prev, <?page=2>; rel=next'], 'page=2'],
            'commas quotes escapes and whitespace' => [[' , <?cursor=a,b>; title="quoted \"text\"; with, commas"; unused; ; rel = "NeXt"; , , '], 'cursor=a,b'],
            'empty list elements' => [[' , , '], null],
        ];
    }

    #[DataProvider('invalidLinkHeaders')]
    public function testMalformedOrContradictoryPaginationLinksFail(string $header): void
    {
        $manager = $this->manager();
        $manager->fake([PagedRequestStub::class => MockResponse::make(['data' => [1]], headers: ['Link' => $header])]);
        $paginator = new LinkPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub);

        $this->expectException(PaginationException::class);

        $paginator->current();
    }

    /**
     * Provide structural errors and unusable pagination targets.
     */
    public static function invalidLinkHeaders(): array
    {
        return [
            'missing bracket' => ['?page=2; rel=next'],
            'unclosed target' => ['<?page=2; rel=next'],
            'unclosed quote' => ['<?page=2>; rel="next'],
            'missing comma' => ['<?page=2>; rel=next <?page=3>; rel=last'],
            'unexpected text' => ['<?page=2> trailing'],
            'conflicting next' => ['<?page=2>; rel=next, <?page=3>; rel=next'],
            'conflicting last' => ['<?page=2>; rel=last, <?page=3>; rel=last'],
            'different scheme' => ['<http://api.example.com/paged?page=2>; rel=next'],
            'different host' => ['<https://other.example.com/paged?page=2>; rel=next'],
            'different port' => ['<https://api.example.com:8443/paged?page=2>; rel=next'],
            'different path' => ['</other?page=2>; rel=next'],
            'last different path' => ['</other?page=2>; rel=last'],
        ];
    }

    public function testSequentialLinkPaginationDoesNotInterpretLastPageNumbers(): void
    {
        $manager = $this->manager();
        $queries = [];
        $manager->fake([PagedRequestStub::class => static function (PendingRequest $pendingRequest) use (&$queries): MockResponse {
            $queries[] = $pendingRequest->uri()->getQuery();

            return MockResponse::make(['data' => [count($queries)]], headers: ['Link' => count($queries) === 1
                ? '<?page=opaque-cursor>; rel=next, <?page=0>; rel=last'
                : '<?page=older-cursor>; rel=last']);
        }]);
        $paginator = new LinkPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub);

        $this->assertSame([1, 2], $paginator->collect()->all());
        $this->assertSame(['page=1', 'page=opaque-cursor'], $queries);
    }

    #[DataProvider('invalidPooledLastLinks')]
    public function testLinkPoolRejectsInvalidOrContradictoryLastPageNumbers(string $header): void
    {
        $manager = $this->manager();
        $manager->fake([PagedRequestStub::class => MockResponse::make(['data' => [1]], headers: ['Link' => $header])]);
        $paginator = new LinkPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub);

        $this->expectException(PaginationException::class);

        $paginator->pool();
    }

    /**
     * Provide unusable or contradictory last pages for a pooled range.
     */
    public static function invalidPooledLastLinks(): array
    {
        return [
            'fractional last' => ['<?page=1.5>; rel=last'],
            'oversized last' => ['<?page=999999999999999999999999999999>; rel=last'],
            'repeated page number' => ['<?page=2&page=3>; rel=last'],
            'next at last page' => ['<?page=2>; rel=next, <?page=1>; rel=last'],
            'next beyond last page' => ['<?page=2>; rel=next, <?page=0>; rel=last'],
            'last after terminal page' => ['<?page=2>; rel=last'],
        ];
    }

    #[DataProvider('completedLastLinks')]
    public function testLinkPoolAcceptsACompletedPageAtOrBeyondTheLastPage(int $startPage, int $lastPage, array $items): void
    {
        $manager = $this->manager();
        $manager->fake([PagedRequestStub::class => MockResponse::make(['data' => $items], headers: [
            'Link' => "<?page={$lastPage}>; rel=last",
        ])]);
        $paginator = (new LinkPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub))->startPage($startPage);

        $this->assertSame($items, $paginator->collect()->all());
        $this->assertCount(1, $paginator->pool());
        $this->assertSame(count($items), $paginator->totalResults());
    }

    /**
     * Provide zero-based, empty and shrinking completed collections.
     */
    public static function completedLastLinks(): array
    {
        return [
            'zero-based single page' => [0, 0, [1]],
            'empty collection' => [0, -1, []],
            'shrinking collection' => [5, 2, []],
        ];
    }

    public function testLinkPoolUsesConfiguredNumberedRequestsAndTheDeclaredLastPage(): void
    {
        $manager = $this->manager();
        $queries = [];
        $manager->fake([PagedRequestStub::class => static function (PendingRequest $pendingRequest) use (&$queries): MockResponse {
            $query = $pendingRequest->queryParameters();
            $queries[] = $query;

            return MockResponse::make(['data' => [$query['number']]], headers: [
                'Link' => '<?number=3&size=9>; rel=next, <?number=4&size=9>; rel=last',
            ]);
        }]);
        $paginator = (new RenamedLinkPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub))
            ->startPage(2)->perPageLimit(5);

        $responses = $paginator->pool();

        $this->assertSame([0, 1, 2], array_keys($responses));
        usort($queries, static fn (array $first, array $second): int => $first['number'] <=> $second['number']);
        $this->assertSame([['number' => 2, 'size' => 5], ['number' => 3, 'size' => 5], ['number' => 4, 'size' => 5]], $queries);
        $this->assertSame(3, $paginator->totalResults());
    }

    #[DataProvider('unnumberedLastLinks')]
    public function testLinkPoolRejectsAnUnknownPageCount(string $header): void
    {
        $manager = $this->manager();
        $manager->fake([PagedRequestStub::class => MockResponse::make(['data' => [1]], headers: ['Link' => $header])]);
        $paginator = new LinkPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub);

        $this->expectException(PaginationException::class);
        $this->expectExceptionMessage('Pooled Link pagination requires a numbered last Link.');

        $paginator->pool();
    }

    /**
     * Provide next links without an independently addressable last page.
     */
    public static function unnumberedLastLinks(): array
    {
        return [['<?cursor=next>; rel=next'], ['<?cursor=next>; rel=next, <?cursor=last>; rel=last']];
    }

    public function testLinkPoolWithoutLinksFetchesOnlyTheStartingPage(): void
    {
        $manager = $this->manager();
        $manager->fake([PagedRequestStub::class => MockResponse::make(['data' => [1]])]);
        $paginator = (new LinkPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub))->startPage(5);

        $this->assertCount(1, $paginator->pool());
        $this->assertSame(1, $paginator->totalResults());
    }

    public function testLinkPaginationMayStartAtPageZero(): void
    {
        $manager = $this->manager();
        $manager->fake([PagedRequestStub::class => static function (PendingRequest $pendingRequest): MockResponse {
            $page = (int) $pendingRequest->queryParameters()['page'];

            return MockResponse::make(['data' => [$page]], headers: $page === 0 ? [
                'Link' => '<?page=1>; rel="next last"',
            ] : []);
        }]);
        $paginator = (new LinkPaginatorStub(new PaginationConnectorStub($manager), new PagedRequestStub))->startPage(0);

        $this->assertCount(2, $paginator->pool());
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
    public object $mapping;

    /**
     * Share mapping observations across request clones.
     */
    public function __construct()
    {
        $this->mapping = (object) ['calls' => 0];
    }

    public function mapPaginatedResponseItems(Response $response): array
    {
        ++$this->mapping->calls;

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

class MappingPagedPaginatorStub extends PagedPaginatorStub
{
    public array $mappedPages = [];

    public ?Closure $mapItems = null;

    public ?Closure $resolvePages = null;

    /**
     * Track mapping before returning or rejecting the page items.
     */
    protected function getPageItems(Response $response, Request $request): array
    {
        $this->mappedPages[] = $response->json('page');

        return $this->mapItems !== null ? ($this->mapItems)($response) : parent::getPageItems($response, $request);
    }

    /**
     * Observe item lifetime when the pool resolves its page count.
     */
    protected function getTotalPages(Response $response): int
    {
        return $this->resolvePages !== null ? ($this->resolvePages)() : parent::getTotalPages($response);
    }
}

class RenamedPagedPaginatorStub extends NeverEndingPagedPaginatorStub
{
    protected string $pageName = 'number';

    protected string $perPageName = 'size';
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

class RenamedOffsetPaginatorStub extends OffsetPaginatorStub
{
    protected string $limitName = 'take';

    protected string $offsetName = 'skip';

    /**
     * Keep requesting pages until the configured test limit.
     */
    protected function isLastPage(Response $response): bool
    {
        return false;
    }
}

class RenamedCursorPaginatorStub extends CursorPaginatorStub
{
    protected string $cursorName = 'after';

    protected string $perPageName = 'size';
}

class LinkPaginatorStub extends LinkHeaderPaginator
{
    /**
     * Map the items carried by the test API.
     */
    protected function getPageItems(Response $response, Request $request): array
    {
        return $response->json('data');
    }
}

class RenamedLinkPaginatorStub extends LinkPaginatorStub
{
    protected string $pageName = 'number';

    protected string $perPageName = 'size';
}
