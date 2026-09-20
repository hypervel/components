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
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Console\Fixtures\FakeEventMutex;
use Mockery as m;

class EventPingTest extends TestCase
{
    public function testPingRescuesTransferExceptions(): void
    {
        $this->spy(ExceptionHandler::class)
            ->expects('report')
            ->with(m::type(ServerException::class));

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
    }
}
