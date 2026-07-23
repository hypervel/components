<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Server\Event;
use Hypervel\Server\Exceptions\InvalidArgumentException as ServerInvalidArgumentException;
use Hypervel\Server\Exceptions\ServerException;
use Hypervel\Server\Port;
use Hypervel\Server\Server;
use Hypervel\Server\ServerConfig;
use Hypervel\Server\ServerInterface;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server as SwooleServer;
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

    public function testServerEventRegistrationFailureStopsConfiguration(): void
    {
        $nativeServer = m::mock(SwooleServer::class);
        $nativeServer->expects('on')->with(Event::ON_WORKER_START, m::type('callable'))->andReturnFalse();

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('Failed to register event [workerStart] on server [test].');

        $this->server(m::mock(Container::class))->registerEvents($nativeServer, [
            Event::ON_WORKER_START => static function (): void {
            },
        ]);
    }

    public function testPortEventRegistrationFailureStopsConfiguration(): void
    {
        $nativePort = m::mock(SwoolePort::class);
        $nativePort->expects('on')->with(Event::ON_REQUEST, m::type('callable'))->andReturnFalse();

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('Failed to register event [request] on server [test].');

        $this->server(m::mock(Container::class))->registerEvents($nativePort, [
            Event::ON_REQUEST => static function (SwooleRequest $request, SwooleResponse $response): void {
            },
        ]);
    }

    public function testMainServerSettingsFailureStopsConfiguration(): void
    {
        $nativeServer = m::mock(SwooleServer::class);
        $nativeServer->expects('set')->with([])->andReturnFalse();
        $server = $this->server(m::mock(Container::class));
        $server->createWith($nativeServer);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('Failed to configure server [http].');

        $server->init(new ServerConfig([
            'servers' => [
                ['name' => 'http'],
            ],
        ]));
    }

    public function testListenerCreationFailureStopsConfiguration(): void
    {
        $mainPort = m::mock(SwoolePort::class);
        $nativeServer = m::mock(SwooleServer::class);
        $nativeServer->ports = [$mainPort];
        $nativeServer->expects('set')->with([])->andReturnTrue();
        $nativeServer->expects('addlistener')->with('127.0.0.1', 8001, SWOOLE_SOCK_TCP)->andReturnFalse();
        $server = $this->server(m::mock(Container::class));
        $server->createWith($nativeServer);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('Failed to listen on server port [127.0.0.1:8001].');

        $server->init(new ServerConfig([
            'servers' => [
                ['name' => 'http'],
                ['name' => 'grpc', 'host' => '127.0.0.1', 'port' => 8001],
            ],
        ]));
    }

    public function testUnsupportedServerTypeUsesThePackageInvalidArgumentException(): void
    {
        $this->expectException(ServerInvalidArgumentException::class);
        $this->expectExceptionMessage('Server type is invalid.');

        $this->server(m::mock(Container::class))->init(new ServerConfig([
            'servers' => [
                ['name' => 'invalid', 'type' => 999],
            ],
        ]));
    }

    public function testStartFailureIsReported(): void
    {
        $nativeServer = m::mock(SwooleServer::class);
        $nativeServer->expects('start')->andReturnFalse();
        $server = $this->server(m::mock(Container::class));
        $server->useNativeServer($nativeServer);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('Failed to start the Swoole server.');

        $server->start();
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
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->allows('dispatch')->andReturnNull();

        return new ServerTestServer(
            $container,
            m::mock(LoggerInterface::class),
            $dispatcher,
        );
    }
}

class ServerTestServer extends Server
{
    private ?SwooleServer $serverToCreate = null;

    public function createWith(SwooleServer $server): void
    {
        $this->serverToCreate = $server;
    }

    public function useNativeServer(SwooleServer $server): void
    {
        $this->server = $server;
    }

    public function guardedResponseCallback(callable $callback): Closure
    {
        return $this->guardResponseCallback($callback);
    }

    public function registerEvents(SwoolePort|SwooleServer $server, array $events): void
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

    protected function makeServer(int $type, string $host, int $port, int $mode, int $sockType): SwooleServer
    {
        return $this->serverToCreate ?? parent::makeServer($type, $host, $port, $mode, $sockType);
    }

    protected function defaultCallbacks(): array
    {
        return [];
    }
}
