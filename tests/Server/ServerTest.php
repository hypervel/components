<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\BeforeMainServerStart;
use Hypervel\Core\Events\BeforeServerFork;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Server\Event;
use Hypervel\Server\Exceptions\InvalidArgumentException as ServerInvalidArgumentException;
use Hypervel\Server\Exceptions\ServerException;
use Hypervel\Server\Port;
use Hypervel\Server\ResponseCancellation;
use Hypervel\Server\Server;
use Hypervel\Server\ServerConfig;
use Hypervel\Server\ServerInterface;
use Hypervel\Server\ServerManager;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server as SwooleServer;
use Swoole\Server\Port as SwoolePort;

class ServerTest extends TestCase
{
    use VerifiesDoubles;

    public function testResponseCallbackReportsAnEscapedExceptionAndCompletesTheResponse(): void
    {
        $exception = new RuntimeException('request callback failed');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($exception));
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with(ExceptionHandler::class)->returns($handler);
        $response = Double::for(SwooleResponse::class)->strict();
        $response->expects('isWritable')->returns(true);
        $response->expects('end')->returns(true);
        $callback = $this->server($container->instance())->guardedResponseCallback(
            static function (SwooleRequest $request, SwooleResponse $response) use ($exception): never {
                throw $exception;
            },
        );

        $callback(Double::for(SwooleRequest::class)->strict(), $response);
    }

    public function testResponseCallbackDoesNotReportCancellation(): void
    {
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->never();
        $response = Double::for(SwooleResponse::class)->strict();
        $response->expects('isWritable')->returns(true);
        $response->expects('end')->returns(true);
        $callback = $this->server($container->instance())->guardedResponseCallback(
            static function (SwooleRequest $request, SwooleResponse $response): never {
                throw new CanceledException;
            },
        );

        $callback(Double::for(SwooleRequest::class)->strict(), $response);
    }

    public function testResponseCallbackDoesNotCompleteAnUnwritableResponse(): void
    {
        $exception = new RuntimeException('request callback failed');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($exception));
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with(ExceptionHandler::class)->returns($handler);
        $response = Double::for(SwooleResponse::class)->strict();
        $response->expects('isWritable')->returns(false);
        $response->expects('end')->never();
        $callback = $this->server($container->instance())->guardedResponseCallback(
            static function (SwooleRequest $request, SwooleResponse $response) use ($exception): never {
                throw $exception;
            },
        );

        $callback(Double::for(SwooleRequest::class)->strict(), $response);
    }

    public function testResponseCallbackFallsBackToThePhpErrorLogAndContainsCompletionFailures(): void
    {
        $directory = ParallelTesting::tempDir('ServerTest');
        (new Filesystem)->deleteDirectory($directory);
        mkdir($directory, 0777, true);
        $errorLog = $directory . '/php-error.log';
        $previousErrorLog = ini_set('error_log', $errorLog);
        $exception = new RuntimeException('request callback failed');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($exception))->throws(new RuntimeException('reporting failed'));
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with(ExceptionHandler::class)->returns($handler);
        $response = Double::for(SwooleResponse::class)->strict();
        $response->expects('isWritable')->returns(true);
        $response->expects('end')->throws(new RuntimeException('completion failed'));
        $callback = $this->server($container->instance())->guardedResponseCallback(
            static function (SwooleRequest $request, SwooleResponse $response) use ($exception): never {
                throw $exception;
            },
        );

        try {
            $callback(Double::for(SwooleRequest::class)->strict(), $response);
            $contents = file_get_contents($errorLog);

            $this->assertIsString($contents);
            $this->assertStringContainsString('request callback failed', $contents);
            $this->assertStringContainsString('reporting failed', $contents);
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }

            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function testResponseAndWorkerDeliveryEventsReceiveTheirCallbackGuards(): void
    {
        $requestCallback = static function (SwooleRequest $request, SwooleResponse $response): void {
        };
        $handshakeCallback = static function (SwooleRequest $request, SwooleResponse $response): void {
        };
        $pipeCallback = static function (SwooleServer $server, int $fromWorkerId, mixed $data): void {
        };
        $finishCallback = static function (SwooleServer $server, int $taskId, mixed $data): void {
        };
        $taskCallback = static function (): void {
        };
        $messageCallback = static function (): void {
        };
        $workerCallback = static function (): void {
        };
        $registered = [];
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->expects('on')->times(7)->resolves(
            static function (string $event, callable $callback) use (&$registered): bool {
                $registered[$event] = $callback;

                return true;
            },
        );

        $this->server(Double::for(Container::class, override: true)->strict()->instance())->registerEvents($nativeServer, [
            Event::ON_REQUEST => $requestCallback,
            Event::ON_HANDSHAKE => $handshakeCallback,
            Event::ON_PIPE_MESSAGE => $pipeCallback,
            Event::ON_FINISH => $finishCallback,
            Event::ON_TASK => $taskCallback,
            Event::ON_MESSAGE => $messageCallback,
            Event::ON_WORKER_START => $workerCallback,
        ]);

        $this->assertNotSame($requestCallback, $registered[Event::ON_REQUEST]);
        $this->assertNotSame($handshakeCallback, $registered[Event::ON_HANDSHAKE]);
        $this->assertNotSame($pipeCallback, $registered[Event::ON_PIPE_MESSAGE]);
        $this->assertNotSame($finishCallback, $registered[Event::ON_FINISH]);
        $this->assertSame($taskCallback, $registered[Event::ON_TASK]);
        $this->assertSame($messageCallback, $registered[Event::ON_MESSAGE]);
        $this->assertSame($workerCallback, $registered[Event::ON_WORKER_START]);
    }

    public function testWorkerDeliveryCallbackReportsAnEscapedException(): void
    {
        $exception = new RuntimeException('worker delivery callback failed');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($exception));
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with(ExceptionHandler::class)->returns($handler);
        $callback = $this->server($container->instance())->guardedWorkerDeliveryCallback(
            static function (SwooleServer $server, int $sourceId, mixed $data) use ($exception): never {
                throw $exception;
            },
        );

        $callback(Double::for(SwooleServer::class)->strict(), 1, ['message' => true]);
    }

    public function testWorkerDeliveryCallbackDoesNotReportCancellation(): void
    {
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->never();
        $callback = $this->server($container->instance())->guardedWorkerDeliveryCallback(
            static function (SwooleServer $server, int $sourceId, mixed $data): never {
                throw new CanceledException;
            },
        );

        $callback(Double::for(SwooleServer::class)->strict(), 1, ['message' => true]);
    }

    public function testWorkerDeliveryCallbackFallsBackToThePhpErrorLog(): void
    {
        $directory = ParallelTesting::tempDir('ServerWorkerDeliveryCallbackTest');
        (new Filesystem)->deleteDirectory($directory);
        mkdir($directory, 0777, true);
        $errorLog = $directory . '/php-error.log';
        $previousErrorLog = ini_set('error_log', $errorLog);
        $exception = new RuntimeException('worker delivery callback failed');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with(Argument::same($exception))->throws(new RuntimeException('reporting failed'));
        $container = Double::for(Container::class, override: true)->strict();
        $container->expects('make')->with(ExceptionHandler::class)->returns($handler);
        $callback = $this->server($container->instance())->guardedWorkerDeliveryCallback(
            static function (SwooleServer $server, int $sourceId, mixed $data) use ($exception): never {
                throw $exception;
            },
        );

        try {
            $callback(Double::for(SwooleServer::class)->strict(), 1, ['message' => true]);
            $contents = file_get_contents($errorLog);

            $this->assertIsString($contents);
            $this->assertStringContainsString('worker delivery callback failed', $contents);
            $this->assertStringContainsString('reporting failed', $contents);
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }

            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function testServerEventRegistrationFailureStopsConfiguration(): void
    {
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->expects('on')->with(Event::ON_WORKER_START, Argument::type('callable'))->returns(false);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessageIs('Failed to register event [workerStart] on server [test].');

        $this->server(Double::for(Container::class, override: true)->strict()->instance())->registerEvents($nativeServer, [
            Event::ON_WORKER_START => static function (): void {
            },
        ]);
    }

    public function testPortEventRegistrationFailureStopsConfiguration(): void
    {
        $nativePort = Double::for(SwoolePort::class)->strict();
        $nativePort->expects('on')->with(Event::ON_REQUEST, Argument::type('callable'))->returns(false);
        $server = $this->server(Double::for(Container::class, override: true)->strict()->instance());
        $server->useNativeServer(Double::for(SwooleServer::class)->strict());

        $this->expectException(ServerException::class);
        $this->expectExceptionMessageIs('Failed to register event [request] on server [test].');

        $server->registerEvents($nativePort, [
            Event::ON_REQUEST => static function (SwooleRequest $request, SwooleResponse $response): void {
            },
        ]);
    }

    #[DataProvider('responseCancellationModes')]
    public function testDisconnectHandlingPreservesApplicationCallbacksAndRespectsServerModes(int $mode, array $settings, bool $supported): void
    {
        $registered = [];
        $received = [];
        $applicationCallback = static function (SwooleServer $server, int $connection, int $reactor) use (&$received): void {
            $received = [$server, $connection, $reactor];
        };
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->mode = $mode;
        $nativeServer->setting = $settings;
        $nativeServer->expects('on')->times(2)->resolves(
            static function (string $event, callable $callback) use (&$registered): bool {
                $registered[$event] = $callback;

                return true;
            },
        );

        $this->server(Double::for(Container::class, override: true)->strict()->instance())->registerEvents($nativeServer, [
            Event::ON_REQUEST => static function (): void {
            },
            Event::ON_CLOSE => $applicationCallback,
        ]);

        $this->assertSame($supported, $registered[Event::ON_CLOSE] !== $applicationCallback);
        $registered[Event::ON_CLOSE]($nativeServer, 123, 2);
        $this->assertSame([$nativeServer, 123, 2], $received);
    }

    /**
     * Provide modes with and without connection ownership in the serving worker.
     */
    public static function responseCancellationModes(): array
    {
        return [
            'base mode' => [SWOOLE_BASE, [], true],
            'default process mode' => [SWOOLE_PROCESS, [], true],
            'IP dispatch' => [SWOOLE_PROCESS, ['dispatch_mode' => 4], true],
            'connection balancing' => [SWOOLE_PROCESS, ['dispatch_mode' => 8], true],
            'round robin' => [SWOOLE_PROCESS, ['dispatch_mode' => 1], false],
            'queue dispatch' => [SWOOLE_PROCESS, ['dispatch_mode' => 3], false],
            'coroutines disabled' => [SWOOLE_PROCESS, ['enable_coroutine' => false], false],
        ];
    }

    public function testResponseDisconnectCallbackIsInstalledWithoutAnApplicationCallback(): void
    {
        $registered = [];
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->mode = SWOOLE_PROCESS;
        $nativeServer->expects('on')->times(2)->resolves(
            static function (string $event, callable $callback) use (&$registered): bool {
                $registered[$event] = $callback;

                return true;
            },
        );

        $this->server(Double::for(Container::class, override: true)->strict()->instance())->registerEvents($nativeServer, [
            Event::ON_REQUEST => static function (): void {
            },
        ]);

        $this->assertArrayHasKey(Event::ON_CLOSE, $registered);
        $registered[Event::ON_CLOSE]($nativeServer, 123, 2);
    }

    public function testMainServerSettingsFailureStopsConfiguration(): void
    {
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->expects('set')->with([])->returns(false);
        $server = $this->server(Double::for(Container::class, override: true)->strict()->instance());
        $server->createWith($nativeServer);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessageIs('Failed to configure server [http].');

        $server->init(new ServerConfig([
            'servers' => [
                ['name' => 'http'],
            ],
        ]));
    }

    public function testSecondaryServerSettingsFailureStopsConfigurationBeforeRegistration(): void
    {
        $secondary = Double::for(SwoolePort::class)->strict();
        $secondary->expects('set')->with([])->returns(false);
        $secondary->expects('on')->never();
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->ports = [Double::for(SwoolePort::class)->strict()];
        $nativeServer->expects('set')->with([])->returns(true);
        $nativeServer->expects('addlistener')->with('127.0.0.1', 8001, SWOOLE_SOCK_TCP)->returns($secondary);
        $server = $this->server(Double::for(Container::class, override: true)->strict()->instance());
        $server->createWith($nativeServer);

        try {
            $server->init(new ServerConfig([
                'servers' => [
                    ['name' => 'http', 'host' => '127.0.0.1', 'port' => 8000],
                    ['name' => 'tcp', 'host' => '127.0.0.1', 'port' => 8001, 'callbacks' => [
                        Event::ON_RECEIVE => static function (): void {
                        },
                    ]],
                ],
            ]));

            $this->fail('No exception was thrown.');
        } catch (ServerException $exception) {
            $this->assertSame('Failed to configure server [tcp].', $exception->getMessage());
        }

        $this->assertFalse(ServerManager::has('tcp'));
    }

    public function testEverySecondaryServerReceivesGlobalAndOnlyItsOwnSettings(): void
    {
        $mainPort = Double::for(SwoolePort::class)->strict();
        $firstSecondary = Double::for(SwoolePort::class)->strict();
        $secondSecondary = Double::for(SwoolePort::class)->strict();
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->ports = [$mainPort];
        $nativeServer->expects('set')->with([
            'socket_buffer_size' => 2048,
            'http_compression' => false,
            'open_http2_protocol' => true,
        ])->returns(true);
        $nativeServer->expects('addlistener')
            ->with('127.0.0.1', 8001, SWOOLE_SOCK_TCP)
            ->returns($firstSecondary);
        $firstSecondary->expects('set')->with([
            'socket_buffer_size' => 4096,
            'http_compression' => false,
            'open_http_protocol' => false,
        ])->returns(true);
        $nativeServer->expects('addlistener')
            ->with('127.0.0.1', 8002, SWOOLE_SOCK_TCP)
            ->returns($secondSecondary);
        $secondSecondary->expects('set')->with([
            'socket_buffer_size' => 2048,
            'http_compression' => false,
        ])->returns(true);
        $server = $this->server(Double::for(Container::class, override: true)->strict()->instance());
        $server->createWith($nativeServer);

        $server->init(new ServerConfig([
            'settings' => [
                'socket_buffer_size' => 2048,
                'http_compression' => false,
            ],
            'servers' => [
                [
                    'name' => 'http',
                    'host' => '127.0.0.1',
                    'port' => 8000,
                    'settings' => ['open_http2_protocol' => true],
                ],
                [
                    'name' => 'grpc',
                    'host' => '127.0.0.1',
                    'port' => 8001,
                    'settings' => [
                        'socket_buffer_size' => 4096,
                        'open_http_protocol' => false,
                    ],
                ],
                [
                    'name' => 'metrics',
                    'host' => '127.0.0.1',
                    'port' => 8002,
                ],
            ],
        ]));
    }

    public function testInheritedResponseCallbacksUseTheirReceivingListenerAndPreserveCloseHandling(): void
    {
        $registered = [];
        $received = [];
        $closed = [];
        $requestCallback = static function (SwooleRequest $request) use (&$received): void {
            $received[] = $request->server['https'] ?? null;
        };
        $closeCallback = static function (SwooleServer $server, int $connection) use (&$closed): void {
            $closed[] = $connection;
        };
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->mode = SWOOLE_BASE;
        $nativeServer->ssl = false;
        $nativeServer->ports = [Double::for(SwoolePort::class)->strict()];
        $inherited = Double::for(SwoolePort::class)->strict();
        $inherited->ssl = true;
        $overridden = Double::for(SwoolePort::class)->strict();
        $overridden->ssl = false;

        foreach ([$nativeServer, $inherited, $overridden] as $index => $listener) {
            $listener->expects('set')->with([])->returns(true);
            $listener->expects('on')->times(3)->resolves(
                static function (string $event, callable $callback) use (&$registered, $index): bool {
                    $registered[$index][$event] = $callback;

                    return true;
                },
            );
        }

        $nativeServer->expects('addlistener')->with('127.0.0.2', 8443, SWOOLE_SOCK_TCP | SWOOLE_SSL)->returns($inherited);
        $nativeServer->expects('addlistener')->with('127.0.0.1', 8080, SWOOLE_SOCK_TCP)->returns($overridden);
        $server = $this->server(Double::for(Container::class, override: true)->strict()->instance());
        $server->createWith($nativeServer);
        $server->init(new ServerConfig(['servers' => [
            ['name' => 'http', 'host' => '127.0.0.1', 'port' => 8443, 'callbacks' => [
                Event::ON_REQUEST => $requestCallback,
                Event::ON_HANDSHAKE => $requestCallback,
                Event::ON_CLOSE => $closeCallback,
            ]],
            ['name' => 'https', 'host' => '127.0.0.2', 'port' => 8443, 'sock_type' => SWOOLE_SOCK_TCP | SWOOLE_SSL],
            ['name' => 'other', 'host' => '127.0.0.1', 'port' => 8080, 'callbacks' => [
                Event::ON_HANDSHAKE => static function () use (&$received): void {
                    $received[] = 'custom handshake';
                },
            ]],
        ]]));

        foreach ([0, 1, 2] as $index) {
            foreach ([Event::ON_REQUEST, Event::ON_HANDSHAKE] as $event) {
                $request = Double::for(SwooleRequest::class)->strict();
                $request->server = [];
                $registered[$index][$event]($request, Double::for(SwooleResponse::class)->strict());
            }
        }

        $this->assertSame([null, null, 'on', 'on', null, 'custom handshake'], $received);

        $waiting = new Channel(1);
        $completed = new Channel(1);
        Coroutine::create(static function () use ($waiting, $completed): void {
            $registration = ResponseCancellation::register(123);

            try {
                $waiting->pop(1);
                $completed->push(false);
            } catch (CanceledException) {
                $completed->push(true);
            } finally {
                $registration?->release();
            }
        });

        try {
            $registered[1][Event::ON_CLOSE]($nativeServer, 123, 0);
            $this->assertTrue($completed->pop(1));
            $this->assertSame([123], $closed);
        } finally {
            $waiting->close();
            $completed->close();
        }
    }

    public function testServerInitializationDispatchesLifecycleEventsWhenListenedFor(): void
    {
        $mainPort = Double::for(SwoolePort::class)->strict();
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->ports = [$mainPort];
        $nativeServer->expects('set')->with([])->returns(true);

        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('hasListeners')->with(BeforeMainServerStart::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::satisfies(static fn (mixed $event): bool => $event instanceof BeforeMainServerStart
                && $event->server === $nativeServer
                && $event->serverConfig['servers'][0]->getName() === 'http'))
            ->returns(null);
        $dispatcher->expects('hasListeners')->with(BeforeServerStart::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::satisfies(static fn (mixed $event): bool => $event instanceof BeforeServerStart
                && $event->serverName === 'http'))
            ->returns(null);

        $server = new ServerTestServer(
            Double::for(Container::class, override: true)->strict()->instance(),
            Double::for(LoggerInterface::class)->strict(),
            $dispatcher,
        );
        $server->createWith($nativeServer);

        $server->init(new ServerConfig([
            'servers' => [
                ['name' => 'http'],
            ],
        ]));
    }

    public function testListenerCreationFailureStopsConfiguration(): void
    {
        $mainPort = Double::for(SwoolePort::class)->strict();
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->ports = [$mainPort];
        $nativeServer->expects('set')->with([])->returns(true);
        $nativeServer->expects('addlistener')->with('127.0.0.1', 8001, SWOOLE_SOCK_TCP)->returns(false);
        $server = $this->server(Double::for(Container::class, override: true)->strict()->instance());
        $server->createWith($nativeServer);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessageIs('Failed to listen on server port [127.0.0.1:8001].');

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
        $this->expectExceptionMessageIs('Server type is invalid.');

        $this->server(Double::for(Container::class, override: true)->strict()->instance())->init(new ServerConfig([
            'servers' => [
                ['name' => 'invalid', 'type' => 999],
            ],
        ]));
    }

    public function testStartFailureIsReported(): void
    {
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->expects('start')->returns(false);
        $server = $this->server(Double::for(Container::class, override: true)->strict()->instance());
        $server->useNativeServer($nativeServer);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessageIs('Failed to start the Swoole server.');

        $server->start();
    }

    public function testBeforeServerForkIsDispatchedBeforeNativeStart(): void
    {
        $order = [];
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->expects('start')->resolves(function () use (&$order): bool {
            $order[] = 'start';

            return true;
        });

        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('hasListeners')->with(BeforeServerFork::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(BeforeServerFork::class))
            ->resolves(function (BeforeServerFork $event) use ($nativeServer, &$order): void {
                $this->assertSame($nativeServer, $event->server);
                $order[] = 'before-fork';
            });

        $server = new ServerTestServer(
            Double::for(Container::class, override: true)->strict()->instance(),
            Double::for(LoggerInterface::class)->strict(),
            $dispatcher,
        );
        $server->useNativeServer($nativeServer);
        $server->start();

        $this->assertSame(['before-fork', 'start'], $order);
    }

    public function testBeforeServerForkFailurePreventsNativeStart(): void
    {
        $exception = new RuntimeException('Pre-fork cleanup failed.');
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->expects('start')->never();

        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('hasListeners')->with(BeforeServerFork::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(BeforeServerFork::class))
            ->throws($exception);

        $server = new ServerTestServer(
            Double::for(Container::class, override: true)->strict()->instance(),
            Double::for(LoggerInterface::class)->strict(),
            $dispatcher,
        );
        $server->useNativeServer($nativeServer);

        try {
            $server->start();
            $this->fail('Expected the pre-fork failure to propagate.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }
    }

    public function testStartSkipsLifecycleEventWithoutListeners(): void
    {
        $started = false;
        $nativeServer = Double::for(SwooleServer::class)->strict();
        $nativeServer->expects('start')->resolves(function () use (&$started): bool {
            $started = true;

            return true;
        });

        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->expects('hasListeners')->with(BeforeServerFork::class)->returns(false);
        $dispatcher->expects('dispatch')->never();

        $server = new ServerTestServer(
            Double::for(Container::class, override: true)->strict()->instance(),
            Double::for(LoggerInterface::class)->strict(),
            $dispatcher,
        );
        $server->useNativeServer($nativeServer);

        $server->start();

        $this->assertTrue($started);
    }

    public function testServerTypesArePrioritizedWithoutReorderingEqualTypes(): void
    {
        $server = new ServerTestServer(
            Double::for(Container::class, override: true)->strict()->instance(),
            Double::for(LoggerInterface::class)->strict(),
            Double::for(Dispatcher::class)->strict(),
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
            Double::for(Container::class, override: true)->strict()->instance(),
            Double::for(LoggerInterface::class)->strict(),
            Double::for(Dispatcher::class)->strict(),
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

    /**
     * Create a named server port.
     */
    private function port(string $name, int $type): Port
    {
        return Port::build([
            'name' => $name,
            'type' => $type,
        ]);
    }

    /**
     * Create a server with inactive lifecycle listeners.
     */
    private function server(Container $container): ServerTestServer
    {
        $dispatcher = Double::for(Dispatcher::class)->strict();
        $dispatcher->allows('hasListeners')->returns(false);
        $dispatcher->allows('dispatch')->returns(null);

        return new ServerTestServer(
            $container,
            Double::for(LoggerInterface::class)->strict(),
            $dispatcher,
        );
    }
}

class ServerTestServer extends Server
{
    private ?SwooleServer $serverToCreate = null;

    /**
     * Choose the native server returned during initialization.
     */
    public function createWith(SwooleServer $server): void
    {
        $this->serverToCreate = $server;
    }

    /**
     * Set the native server without initialization.
     */
    public function useNativeServer(SwooleServer $server): void
    {
        $this->server = $server;
    }

    /**
     * Expose the guarded response callback.
     */
    public function guardedResponseCallback(callable $callback): Closure
    {
        return $this->guardResponseCallback($callback, false);
    }

    /**
     * Expose the guarded worker delivery callback.
     */
    public function guardedWorkerDeliveryCallback(callable $callback): Closure
    {
        return $this->guardWorkerDeliveryCallback($callback);
    }

    /**
     * Register callbacks on the test listener.
     */
    public function registerEvents(SwoolePort|SwooleServer $server, array $events): void
    {
        $this->registerSwooleEvents($server, $events, 'test');
    }

    /**
     * Expose server sorting without starting any listeners.
     *
     * @param list<Port> $servers
     * @return list<Port>
     */
    public function sortedServers(array $servers): array
    {
        return $this->sortServers($servers);
    }

    /**
     * Return the supplied server or create the requested native server.
     */
    protected function makeServer(int $type, string $host, int $port, int $mode, int $sockType): SwooleServer
    {
        return $this->serverToCreate ?? parent::makeServer($type, $host, $port, $mode, $sockType);
    }

    /**
     * Keep default native callbacks out of these tests.
     */
    protected function defaultCallbacks(): array
    {
        return [];
    }
}
