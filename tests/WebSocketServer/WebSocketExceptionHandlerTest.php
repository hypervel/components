<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Tests\TestCase;
use Hypervel\WebSocketServer\Exceptions\Handler\WebSocketExceptionHandler;
use Mockery as m;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WebSocketExceptionHandlerTest extends TestCase
{
    public function testPreservesClientHttpExceptionDetailsAndHeaders(): void
    {
        $handler = $this->handler();
        $exception = new HttpException(429, 'Slow down.', null, ['Retry-After' => '30']);

        $response = $handler->handle($exception, new Response);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('Slow down.', $response->getContent());
        $this->assertSame('30', $response->headers->get('Retry-After'));
    }

    public function testUsesStatusTextForEmptyClientHttpExceptionMessages(): void
    {
        $response = $this->handler()->handle(new HttpException(400), new Response);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Bad Request', $response->getContent());
    }

    public function testHidesServerHttpExceptionDetailsWhilePreservingStatusAndHeaders(): void
    {
        $exception = new HttpException(503, 'Database credentials leaked.', null, ['Retry-After' => '30']);

        $response = $this->handler()->handle($exception, new Response);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Service Unavailable', $response->getContent());
        $this->assertSame('30', $response->headers->get('Retry-After'));
    }

    public function testHidesNonHttpExceptionDetails(): void
    {
        $response = $this->handler()->handle(new RuntimeException('Database credentials leaked.'), new Response);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Internal Server Error', $response->getContent());
    }

    /**
     * Create the exception handler.
     */
    protected function handler(): WebSocketExceptionHandler
    {
        $logger = m::mock(StdoutLoggerInterface::class);
        $logger->shouldReceive('warning')->once();

        return new WebSocketExceptionHandler($logger);
    }
}
