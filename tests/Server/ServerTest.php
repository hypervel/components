<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Server\Event;
use Hypervel\Server\Port;
use Hypervel\Server\Server;
use Hypervel\Server\ServerInterface;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server\Port as SwoolePort;

class ServerTest extends TestCase
{
    public function testResponseCallbackReportsAnEscapedExceptionAndCompletesTheResponse(): void
    {
        $exception = new RuntimeException('request callback failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->expects('report')->with($exception);
        $container = m::mock(Container::class);
        $container->expects('make')->with(ExceptionHandler::class)->andReturn($handler);
        $response = m::mock(SwooleResponse::class);
        $response->expects('isWritable')->andReturnTrue();
        $response->expects('end')->andReturnTrue();
        $callback = $this->server($container)->guardedResponseCallback(
            static function (SwooleRequest $request, SwooleResponse $response) use ($exception): never {
                throw $exception;
            },
        );

        $callback(m::mock(SwooleRequest::class), $response);
    }

    public function testResponseCallbackDoesNotReportCancellation(): void
    {
        $container = m::mock(Container::class);
        $container->shouldNotReceive('make');
        $response = m::mock(SwooleResponse::class);
        $response->expects('isWritable')->andReturnTrue();
        $response->expects('end')->andReturnTrue();
        $callback = $this->server($container)->guardedResponseCallback(
            static function (SwooleRequest $request, SwooleResponse $response): never {
                throw new CanceledException;
            },
        );

        $callback(m::mock(SwooleRequest::class), $response);
    }

    public function testResponseCallbackDoesNotCompleteAnUnwritableResponse(): void
    {
        $exception = new RuntimeException('request callback failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->expects('report')->with($exception);
        $container = m::mock(Container::class);
        $container->expects('make')->with(ExceptionHandler::class)->andReturn($handler);
        $response = m::mock(SwooleResponse::class);
        $response->expects('isWritable')->andReturnFalse();
        $response->shouldNotReceive('end');
        $callback = $this->server($container)->guardedResponseCallback(
            static function (SwooleRequest $request, SwooleResponse $response) use ($exception): never {
                throw $exception;
            },
        );

        $callback(m::mock(SwooleRequest::class), $response);
    }

    public function testResponseCallbackFallsBackToThePhpErrorLogAndContainsCompletionFailures(): void
    {
        $directory = ParallelTesting::tempDir('ServerTest');
        mkdir($directory, 0777, true);
        $errorLog = $directory . '/php-error.log';
        $previousErrorLog = ini_set('error_log', $errorLog);
        $exception = new RuntimeException('request callback failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->expects('report')->with($exception)->andThrow(new RuntimeException('reporting failed'));
        $container = m::mock(Container::class);
        $container->expects('make')->with(ExceptionHandler::class)->andReturn($handler);
        $response = m::mock(SwooleResponse::class);
        $response->expects('isWritable')->andReturnTrue();
        $response->expects('end')->andThrow(new RuntimeException('completion failed'));
        $callback = $this->server($container)->guardedResponseCallback(
            static function (SwooleRequest $request, SwooleResponse $response) use ($exception): never {
                throw $exception;
            },
        );

        try {
            $callback(m::mock(SwooleRequest::class), $response);
            $contents = file_get_contents($errorLog);

            $this->assertIsString($contents);
            $this->assertStringContainsString('request callback failed', $contents);
            $this->assertStringNotContainsString('reporting failed', $contents);
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }

            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function testOnlyResponseBearingEventsReceiveTheCallbackGuard(): void
    {
        $requestCallback = static function (SwooleRequest $request, SwooleResponse $response): void {
        };
        $handshakeCallback = static function (SwooleRequest $request, SwooleResponse $response): void {
        };
        $messageCallback = static function (): void {
        };
        $workerCallback = static function (): void {
        };
        $registered = [];
        $nativeServer = m::mock(SwoolePort::class);
        $nativeServer->expects('on')->times(4)->andReturnUsing(
            static function (string $event, callable $callback) use (&$registered): bool {
                $registered[$event] = $callback;

                return true;
            },
        );

        $this->server(m::mock(Container::class))->registerEvents($nativeServer, [
            Event::ON_REQUEST => $requestCallback,
            Event::ON_HANDSHAKE => $handshakeCallback,
            Event::ON_MESSAGE => $messageCallback,
            Event::ON_WORKER_START => $workerCallback,
        ]);

        $this->assertNotSame($requestCallback, $registered[Event::ON_REQUEST]);
        $this->assertNotSame($handshakeCallback, $registered[Event::ON_HANDSHAKE]);
        $this->assertSame($messageCallback, $registered[Event::ON_MESSAGE]);
        $this->assertSame($workerCallback, $registered[Event::ON_WORKER_START]);
    }

    public function testServerTypesArePrioritizedWithoutReorderingEqualTypes(): void
    {
        $server = new ServerTestServer(
            m::mock(Container::class),
            m::mock(LoggerInterface::class),
            m::mock(Dispatcher::class),
        );

        $ports = [
            $this->port('base-one', ServerInterface::SERVER_BASE),
            $this->port('http-one', ServerInterface::SERVER_HTTP),
            $this->port('websocket-one', ServerInterface::SERVER_WEBSOCKET),
            $this->port('http-two', ServerInterface::SERVER_HTTP),
            $this->port('websocket-two', ServerInterface::SERVER_WEBSOCKET),
            $this->port('base-two', ServerInterface::SERVER_BASE),
        ];

        $this->assertSame(
            ['websocket-one', 'websocket-two', 'http-one', 'http-two', 'base-one', 'base-two'],
            array_map(
                static fn (Port $port): string => $port->getName(),
                $server->sortedServers($ports),
            ),
        );
    }

    public function testAppendedHttpServerCannotReplaceTheConfiguredMainHttpServer(): void
    {
        $server = new ServerTestServer(
            m::mock(Container::class),
            m::mock(LoggerInterface::class),
            m::mock(Dispatcher::class),
        );

        $ports = [
            $this->port('http', ServerInterface::SERVER_HTTP),
            $this->port('grpc', ServerInterface::SERVER_HTTP),
        ];

        $this->assertSame(
            ['http', 'grpc'],
            array_map(
                static fn (Port $port): string => $port->getName(),
                $server->sortedServers($ports),
            ),
        );
    }

    private function port(string $name, int $type): Port
    {
        return Port::build([
            'name' => $name,
            'type' => $type,
        ]);
    }

    private function server(Container $container): ServerTestServer
    {
        return new ServerTestServer(
            $container,
            m::mock(LoggerInterface::class),
            m::mock(Dispatcher::class),
        );
    }
}

class ServerTestServer extends Server
{
    public function guardedResponseCallback(callable $callback): Closure
    {
        return $this->guardResponseCallback($callback);
    }

    public function registerEvents(SwoolePort $server, array $events): void
    {
        $this->registerSwooleEvents($server, $events, 'test');
    }

    /**
     * @param list<Port> $servers
     * @return list<Port>
     */
    public function sortedServers(array $servers): array
    {
        return $this->sortServers($servers);
    }
}
