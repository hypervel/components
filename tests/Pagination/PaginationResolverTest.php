<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Context\Context;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Http\Request;
use Hypervel\Pagination\Cursor;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\PaginationState;
use Hypervel\Pagination\Paginator;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Coroutine\Channel;
use Swow\Psr7\Message\ServerRequestPlusInterface;

use function Hypervel\Coroutine\go;

/**
 * Tests that pagination resolvers work correctly with Swoole's coroutine architecture.
 *
 * The resolvers are set once at bootstrap but read from Context each time,
 * ensuring different coroutines (requests) get their own pagination state.
 *
 * @internal
 * @coversNothing
 */
class PaginationResolverTest extends TestCase
{
    use RunTestsInCoroutine;

    protected function tearDown(): void
    {
        Context::destroy(ServerRequestInterface::class);

        parent::tearDown();
    }

    public function testCurrentPageResolverReadsFromRequest(): void
    {
        $this->setUpMockRequest(['page' => '3']);

        PaginationState::resolveUsing($this->app);

        $this->assertSame(3, Paginator::resolveCurrentPage());
    }

    public function testCurrentPageResolverReturnsOneWhenNoRequest(): void
    {
        // No request in Context
        Context::destroy(ServerRequestInterface::class);

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
        Context::destroy(ServerRequestInterface::class);

        PaginationState::resolveUsing($this->app);

        $this->assertNull(CursorPaginator::resolveCurrentCursor());
    }

    public function testCurrentCursorResolverReturnsNullForInvalidCursor(): void
    {
        $this->setUpMockRequest(['cursor' => 'not-valid-base64!@#']);

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
        Context::destroy(ServerRequestInterface::class);

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
        Context::destroy(ServerRequestInterface::class);

        PaginationState::resolveUsing($this->app);

        $this->assertSame([], Paginator::resolveQueryString());
    }

    public function testCoroutineIsolation(): void
    {
        PaginationState::resolveUsing($this->app);

        $channel = new Channel(2);

        // Coroutine 1: page 5
        go(function () use ($channel) {
            $this->setUpMockRequest(['page' => '5']);
            $channel->push(['coroutine' => 1, 'page' => Paginator::resolveCurrentPage()]);
        });

        // Coroutine 2: page 10
        go(function () use ($channel) {
            $this->setUpMockRequest(['page' => '10']);
            $channel->push(['coroutine' => 2, 'page' => Paginator::resolveCurrentPage()]);
        });

        $results = [];
        $results[] = $channel->pop(1.0);
        $results[] = $channel->pop(1.0);

        // Sort by coroutine number for consistent assertion
        usort($results, fn ($a, $b) => $a['coroutine'] <=> $b['coroutine']);

        $this->assertSame(5, $results[0]['page']);
        $this->assertSame(10, $results[1]['page']);
    }

    public function testCursorCoroutineIsolation(): void
    {
        PaginationState::resolveUsing($this->app);

        $cursor1 = new Cursor(['id' => 100], true);
        $cursor2 = new Cursor(['id' => 200], false);

        $channel = new Channel(2);

        go(function () use ($channel, $cursor1) {
            $this->setUpMockRequest(['cursor' => $cursor1->encode()]);
            $resolved = CursorPaginator::resolveCurrentCursor();
            $channel->push([
                'coroutine' => 1,
                'id' => $resolved->parameter('id'),
                'pointsToNext' => $resolved->pointsToNextItems(),
            ]);
        });

        go(function () use ($channel, $cursor2) {
            $this->setUpMockRequest(['cursor' => $cursor2->encode()]);
            $resolved = CursorPaginator::resolveCurrentCursor();
            $channel->push([
                'coroutine' => 2,
                'id' => $resolved->parameter('id'),
                'pointsToNext' => $resolved->pointsToNextItems(),
            ]);
        });

        $results = [];
        $results[] = $channel->pop(1.0);
        $results[] = $channel->pop(1.0);

        usort($results, fn ($a, $b) => $a['coroutine'] <=> $b['coroutine']);

        $this->assertSame(100, $results[0]['id']);
        $this->assertTrue($results[0]['pointsToNext']);
        $this->assertSame(200, $results[1]['id']);
        $this->assertFalse($results[1]['pointsToNext']);
    }

    /**
     * Set up a mock request in Context with the given query parameters.
     */
    protected function setUpMockRequest(array $queryParams = [], string $url = 'https://example.com'): void
    {
        $psrRequest = m::mock(ServerRequestPlusInterface::class);
        $psrRequest->shouldReceive('getQueryParams')->andReturn($queryParams);
        $psrRequest->shouldReceive('getParsedBody')->andReturn([]);

        Context::set(ServerRequestInterface::class, $psrRequest);

        // Create a Request instance that the resolvers will use
        $request = m::mock(Request::class)->makePartial();
        $request->shouldReceive('input')->andReturnUsing(function ($key, $default = null) use ($queryParams) {
            return $queryParams[$key] ?? $default;
        });
        $request->shouldReceive('query')->andReturn($queryParams);
        $request->shouldReceive('url')->andReturn($url);

        $this->app->instance('request', $request);
    }
}
