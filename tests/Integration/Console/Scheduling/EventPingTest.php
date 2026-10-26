<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Scheduling;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Contracts\Container\Container;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Console\Fixtures\FakeEventMutex;

class EventPingTest extends TestCase
{
    public function testPingRescuesTransferExceptions(): void
    {
        $exceptions = Exceptions::fake();

        $httpMock = new HttpClient([
            'handler' => HandlerStack::create(
                new MockHandler([new Psr7Response(500)])
            ),
        ]);

        $this->swap(HttpClient::class, $httpMock);

        $event = new Event(new FakeEventMutex, 'php -i');

        $thenCalled = false;

        $event->pingBefore('https://httpstat.us/500')
            ->then(function () use (&$thenCalled): void {
                $thenCalled = true;
            });

        $event->callBeforeCallbacks($this->app->make(Container::class));
        $event->callAfterCallbacks($this->app->make(Container::class));

        $this->assertTrue($thenCalled);
        $this->assertCount(1, $exceptions->reported());
        $this->assertInstanceOf(ServerException::class, $exceptions->reported()[0]);
    }
}
