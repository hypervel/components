<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\View\Factory;
use Hypervel\Http\Request;
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Pagination\Cursor;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\PaginationState;
use Hypervel\Pagination\Paginator;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use ReflectionProperty;

use function Hypervel\Coroutine\parallel;

/**
 * Tests that pagination resolvers work correctly with Swoole's coroutine architecture.
 *
 * The resolvers are set once at bootstrap but read from Context each time,
 * ensuring different coroutines (requests) get their own pagination state.
 */
class PaginationResolverTest extends TestCase
{
    public function testCurrentPageResolverReadsFromRequest(): void
    {
        $this->setUpMockRequest(['page' => '3']);

        PaginationState::resolveUsing($this->app);

        $this->assertSame(3, Paginator::resolveCurrentPage());
    }

    public function testCurrentPageResolverReturnsOneWhenNoRequest(): void
    {
        // No request in Context
        RequestContext::forget();

        PaginationState::resolveUsing($this->app);

        $this->assertSame(1, Paginator::resolveCurrentPage());
    }

    public function testCurrentPageResolverReturnsOneForInvalidPage(): void
    {
        $this->setUpMockRequest(['page' => 'invalid']);

        PaginationState::resolveUsing($this->app);

        $this->assertSame(1, Paginator::resolveCurrentPage());
    }

    public function testCurrentPageResolverReturnsOneForNegativePage(): void
    {
        $this->setUpMockRequest(['page' => '-5']);

        PaginationState::resolveUsing($this->app);

        $this->assertSame(1, Paginator::resolveCurrentPage());
    }

    public function testCurrentCursorResolverReadsFromRequest(): void
    {
        $cursor = new Cursor(['id' => 10], true);
        $this->setUpMockRequest(['cursor' => $cursor->encode()]);

        PaginationState::resolveUsing($this->app);

        $resolved = CursorPaginator::resolveCurrentCursor();

        $this->assertInstanceOf(Cursor::class, $resolved);
        $this->assertSame(10, $resolved->parameter('id'));
        $this->assertTrue($resolved->pointsToNextItems());
    }

    public function testCurrentCursorResolverReturnsNullWhenNoRequest(): void
    {
        RequestContext::forget();

        PaginationState::resolveUsing($this->app);

        $this->assertNull(CursorPaginator::resolveCurrentCursor());
    }

    public function testCurrentCursorResolverReturnsNullForInvalidCursor(): void
    {
        $this->setUpMockRequest(['cursor' => 'not-valid-base64!@#']);

        PaginationState::resolveUsing($this->app);

        $this->assertNull(CursorPaginator::resolveCurrentCursor());
    }

    public function testCurrentCursorResolverReturnsNullForArrayInput(): void
    {
        $this->setUpMockRequest(['cursor' => ['invalid']]);

        PaginationState::resolveUsing($this->app);

        $this->assertNull(CursorPaginator::resolveCurrentCursor());
    }

    public function testCurrentPathResolverReadsFromRequest(): void
    {
        $this->setUpMockRequest([], 'https://example.com/users');

        PaginationState::resolveUsing($this->app);

        $this->assertSame('https://example.com/users', Paginator::resolveCurrentPath());
    }

    public function testCurrentPathResolverReturnsSlashWhenNoRequest(): void
    {
        RequestContext::forget();

        PaginationState::resolveUsing($this->app);

        $this->assertSame('/', Paginator::resolveCurrentPath());
    }

    public function testQueryStringResolverReadsFromRequest(): void
    {
        $this->setUpMockRequest(['foo' => 'bar', 'baz' => 'qux']);

        PaginationState::resolveUsing($this->app);

        $this->assertSame(['foo' => 'bar', 'baz' => 'qux'], Paginator::resolveQueryString());
    }

    public function testQueryStringResolverReturnsEmptyArrayWhenNoRequest(): void
    {
        RequestContext::forget();

        PaginationState::resolveUsing($this->app);

        $this->assertSame([], Paginator::resolveQueryString());
    }

    public function testRequestResolversUseRequestContextInsteadOfTheContainerBinding(): void
    {
        $cursor = new Cursor(['id' => 42]);
        $this->app->instance('request', Request::create('https://container.example?cursor=invalid&page=9'));
        RequestContext::set(Request::create(
            'https://context.example/users?cursor=' . $cursor->encode() . '&page=4&sort=name'
        ));

        PaginationState::resolveUsing($this->app);

        $this->assertSame('https://context.example/users', Paginator::resolveCurrentPath());
        $this->assertSame(4, Paginator::resolveCurrentPage());
        $this->assertSame([
            'cursor' => $cursor->encode(),
            'page' => '4',
            'sort' => 'name',
        ], Paginator::resolveQueryString());
        $this->assertSame(42, CursorPaginator::resolveCurrentCursor()?->parameter('id'));
    }

    public function testViewFactoryResolverHonorsLazyContainerRebinding(): void
    {
        PaginationState::resolveUsing($this->app);

        $factory = m::mock(Factory::class);
        $this->app->instance('view', $factory);

        $this->assertSame($factory, Paginator::viewFactory());
    }

    public function testCoroutineIsolation(): void
    {
        PaginationState::resolveUsing($this->app);

        [$firstPage, $secondPage] = parallel([
            function (): int {
                $this->setUpMockRequest(['page' => '5']);
                usleep(5000);

                return Paginator::resolveCurrentPage();
            },
            function (): int {
                $this->setUpMockRequest(['page' => '10']);
                usleep(5000);

                return Paginator::resolveCurrentPage();
            },
        ]);

        $this->assertSame(5, $firstPage);
        $this->assertSame(10, $secondPage);
    }

    public function testCursorCoroutineIsolation(): void
    {
        PaginationState::resolveUsing($this->app);

        $cursor1 = new Cursor(['id' => 100], true);
        $cursor2 = new Cursor(['id' => 200], false);

        $results = parallel([
            function () use ($cursor1): array {
                $this->setUpMockRequest(['cursor' => $cursor1->encode()]);
                usleep(5000);
                $resolved = CursorPaginator::resolveCurrentCursor();

                return [
                    'id' => $resolved->parameter('id'),
                    'pointsToNext' => $resolved->pointsToNextItems(),
                ];
            },
            function () use ($cursor2): array {
                $this->setUpMockRequest(['cursor' => $cursor2->encode()]);
                usleep(5000);
                $resolved = CursorPaginator::resolveCurrentCursor();

                return [
                    'id' => $resolved->parameter('id'),
                    'pointsToNext' => $resolved->pointsToNextItems(),
                ];
            },
        ]);

        $this->assertSame(100, $results[0]['id']);
        $this->assertTrue($results[0]['pointsToNext']);
        $this->assertSame(200, $results[1]['id']);
        $this->assertFalse($results[1]['pointsToNext']);
    }

    public function testFlushStateRestoresEveryStaticPaginationSetting(): void
    {
        $factory = m::mock(Factory::class);
        $cursor = new Cursor(['id' => 10]);

        Paginator::currentPathResolver(fn () => '/custom');
        Paginator::currentPageResolver(fn () => 9);
        Paginator::queryStringResolver(fn () => ['sort' => 'name']);
        Paginator::viewFactoryResolver(fn () => $factory);
        Paginator::defaultView('pagination::custom');
        Paginator::defaultSimpleView('pagination::simple-custom');
        CursorPaginator::currentCursorResolver(fn () => $cursor);

        $this->assertSame('/custom', Paginator::resolveCurrentPath());
        $this->assertSame(9, Paginator::resolveCurrentPage());
        $this->assertSame(['sort' => 'name'], Paginator::resolveQueryString());
        $this->assertSame($factory, Paginator::viewFactory());
        $this->assertSame('pagination::custom', Paginator::$defaultView);
        $this->assertSame('pagination::simple-custom', Paginator::$defaultSimpleView);
        $this->assertSame($cursor, CursorPaginator::resolveCurrentCursor());

        AbstractPaginator::flushState();
        AbstractCursorPaginator::flushState();

        foreach (['currentPathResolver', 'currentPageResolver', 'queryStringResolver', 'viewFactoryResolver'] as $property) {
            $this->assertNull((new ReflectionProperty(AbstractPaginator::class, $property))->getValue());
        }

        $this->assertSame('pagination::tailwind', Paginator::$defaultView);
        $this->assertSame('pagination::simple-tailwind', Paginator::$defaultSimpleView);
        $this->assertNull((new ReflectionProperty(AbstractCursorPaginator::class, 'currentCursorResolver'))->getValue());
    }

    /**
     * Set up a request in Context with the given query parameters.
     */
    protected function setUpMockRequest(array $queryParams = [], string $url = 'https://example.com'): void
    {
        $query = http_build_query($queryParams);
        $fullUrl = $query ? $url . '?' . $query : $url;

        $request = Request::create($fullUrl);
        RequestContext::set($request);
    }
}
