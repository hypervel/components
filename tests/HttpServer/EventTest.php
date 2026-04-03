<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer;

use Hypervel\Http\Request;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\RequestTerminated;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 * @coversNothing
 */
class EventTest extends TestCase
{
    public function testRequestReceivedEvent()
    {
        $request = Request::create('/test');
        $response = new Response('OK');

        $event = new RequestReceived(
            request: $request,
            response: $response,
            server: 'http'
        );

        $this->assertSame($request, $event->request);
        $this->assertSame($response, $event->response);
        $this->assertNull($event->exception);
        $this->assertSame('http', $event->server);
        $this->assertNull($event->getThrowable());
    }

    public function testRequestHandledEvent()
    {
        $request = Request::create('/test');
        $response = new Response('OK');

        $event = new RequestHandled(
            request: $request,
            response: $response,
            server: 'http'
        );

        $this->assertSame($request, $event->request);
        $this->assertSame($response, $event->response);
        $this->assertNull($event->exception);
    }

    public function testRequestTerminatedEventWithException()
    {
        $request = Request::create('/test');
        $response = new Response('Error', 500);
        $exception = new RuntimeException('Something went wrong');

        $event = new RequestTerminated(
            request: $request,
            response: $response,
            exception: $exception,
            server: 'http'
        );

        $this->assertSame($request, $event->request);
        $this->assertSame($response, $event->response);
        $this->assertSame($exception, $event->exception);
        $this->assertSame($exception, $event->getThrowable());
        $this->assertSame('http', $event->server);
    }

    public function testEventDefaultServerName()
    {
        $event = new RequestReceived(
            request: Request::create('/'),
            response: new Response(),
        );

        $this->assertSame('http', $event->server);
    }

    public function testEventWithNullRequestAndResponse()
    {
        $event = new RequestTerminated(
            request: null,
            response: null,
            exception: new RuntimeException('early failure'),
            server: 'http'
        );

        $this->assertNull($event->request);
        $this->assertNull($event->response);
        $this->assertInstanceOf(RuntimeException::class, $event->getThrowable());
    }

    public function testEventWithCustomServerName()
    {
        $event = new RequestReceived(
            request: Request::create('/ws'),
            response: new Response(),
            server: 'ws'
        );

        $this->assertSame('ws', $event->server);
    }
}
