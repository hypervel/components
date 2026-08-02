<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http;

use Hypervel\Http\Client\Events\RequestSending;
use Hypervel\Http\Client\Request;
use Hypervel\Http\Client\Response;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use RuntimeException;

class HttpClientTest extends TestCase
{
    public function testGlobalMiddlewarePersistsBeforeWeDispatchEvent(): void
    {
        Event::fake();
        Http::fake();

        Http::globalRequestMiddleware(fn ($request) => $request->withHeader('User-Agent', 'Facade/1.0'));

        Http::get('hypervel.org');

        Event::assertDispatched(RequestSending::class, function (RequestSending $event) {
            return (new Collection($event->request->header('User-Agent')))->contains('Facade/1.0');
        });
    }

    public function testGlobalMiddlewarePersistsAfterFacadeFlush(): void
    {
        Http::macro('getGlobalMiddleware', fn () => $this->globalMiddleware);
        Http::globalRequestMiddleware(fn ($request) => $request->withHeader('User-Agent', 'Example Application/1.0'));
        Http::globalRequestMiddleware(fn ($request) => $request->withHeader('User-Agent', 'Example Application/1.0'));

        $this->assertCount(2, Http::getGlobalMiddleware());

        Facade::clearResolvedInstances();

        $this->assertCount(2, Http::getGlobalMiddleware());
    }

    // REMOVED: Http::pool() uses Guzzle promise concurrency. In Hypervel, use
    // parallel() with coroutines instead.

    public function testForwardsCallsToPromise(): void
    {
        Http::fake(['*' => Http::response('faked response')]);

        $myFakedResponse = null;
        $r = Http::async()
            ->get('https://hypervel.org')
            ->then(function (Response $response) use (&$myFakedResponse): string {
                $myFakedResponse = $response->getBody();

                return 'stub';
            })
            ->wait();

        $this->assertSame('faked response', (string) $myFakedResponse);
        $this->assertSame('stub', $r);
    }

    public function testCanSetRequestAttributes(): void
    {
        Http::fake([
            '*' => fn (Request $request) => match ($request->attributes()['name'] ?? null) {
                'first' => Http::response('first response'),
                'second' => Http::response('second response'),
                default => Http::response('unnamed')
            },
        ]);

        $response1 = Http::withAttributes(['name' => 'first'])->get('https://some-store.myshopify.com/admin/api/2025-10/graphql.json');
        $response2 = Http::withAttributes(['name' => 'second'])->get('https://some-store.myshopify.com/admin/api/2025-10/graphql.json');
        $response3 = Http::get('https://some-store.myshopify.com/admin/api/2025-10/graphql.json');
        $response4 = Http::withAttributes(['name' => 'fourth'])->get('https://some-store.myshopify.com/admin/api/2025-10/graphql.json');

        $this->assertSame('first response', $response1->body());
        $this->assertSame('second response', $response2->body());
        $this->assertSame('unnamed', $response3->body());
        $this->assertSame('unnamed', $response4->body());
    }

    public function testAsyncCanHandleThrownException(): void
    {
        Http::fake(
            ['*' => Http::response(['luke' => 'kuzmish'])]
        );

        $thrown = new RuntimeException;
        $actual = Http::async()
            ->afterResponse(
                fn (Response $response) => $response->json('luke') === 'kuzmish'
                    ? throw $thrown
                    : null
            )->get('https://cosmastech.com')
            ->wait();

        $this->assertSame($thrown, $actual);
    }
}
