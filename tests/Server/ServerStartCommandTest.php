<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Console\Command as ConsoleCommand;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Foundation\Application;
use Hypervel\Server\Commands\ServerStartCommand;
use Hypervel\Server\ServerFactory;
use Hypervel\Server\ServerInterface;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ServerStartCommandTest extends TestCase
{
    // REMOVED: ServeCommandLogParserTest; Swoole does not emit PHP's built-in-server request logs.

    public function testServeCommandFailsFastWhenRunningInConsoleIsTrue(): void
    {
        $command = new ServerStartCommand($this->app);

        Application::getInstance()->setRunningInConsole(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Error: APP_RUNNING_IN_CONSOLE is true. Your artisan binary may be outdated. Please update it so the serve and watch commands set APP_RUNNING_IN_CONSOLE=false before the server starts.');

        $command->run(new ArrayInput([]), new NullOutput);
    }

    public function testServeCommandUsesThePlainSymfonyRuntimeBoundary(): void
    {
        $command = new ServerStartCommand($this->app);

        $this->assertInstanceOf(SymfonyCommand::class, $command);
        $this->assertNotInstanceOf(ConsoleCommand::class, $command);
    }

    public function testServeCommandStartsServerWhenRunningInConsoleIsFalse(): void
    {
        $serverConfig = [
            'servers' => [
                [
                    'name' => 'http',
                    'type' => ServerInterface::SERVER_HTTP,
                    'host' => '0.0.0.0',
                    'port' => 8000,
                ],
            ],
        ];

        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();
        $serverFactory->shouldReceive('configure')->once()->with($serverConfig);
        $serverFactory->shouldReceive('start')->once();

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->once()->with('server')->andReturn($serverConfig);

        $dispatcher = m::mock(DispatcherContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);

        $this->app->instance(ServerFactory::class, $serverFactory);
        $this->app->instance('events', $dispatcher);
        $this->app->instance(StdoutLoggerInterface::class, $logger);
        $this->app->instance('config', $config);

        $command = new ServerStartCommand($this->app);

        Application::getInstance()->setRunningInConsole(false);

        $result = $command->run(new ArrayInput([]), new NullOutput);

        $this->assertSame(0, $result);
    }

    public function testServeCommandOverridesHttpServerHostAndPort(): void
    {
        $serverConfig = [
            'servers' => [
                [
                    'name' => 'reverb',
                    'type' => ServerInterface::SERVER_WEBSOCKET,
                    'host' => '0.0.0.0',
                    'port' => 8080,
                ],
                [
                    'name' => 'http',
                    'type' => ServerInterface::SERVER_HTTP,
                    'host' => '0.0.0.0',
                    'port' => 8000,
                ],
            ],
        ];

        $expectedServers = [
            [
                'name' => 'reverb',
                'type' => ServerInterface::SERVER_WEBSOCKET,
                'host' => '0.0.0.0',
                'port' => 8080,
            ],
            [
                'name' => 'http',
                'type' => ServerInterface::SERVER_HTTP,
                'host' => '127.0.0.1',
                'port' => 8001,
            ],
        ];

        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();
        $serverFactory->shouldReceive('configure')->once()->with(['servers' => $expectedServers]);
        $serverFactory->shouldReceive('start')->once();

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->once()->with('server')->andReturn($serverConfig);
        $config->shouldReceive('set')->once()->with('server.servers', $expectedServers);

        $dispatcher = m::mock(DispatcherContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);

        $this->app->instance(ServerFactory::class, $serverFactory);
        $this->app->instance('events', $dispatcher);
        $this->app->instance(StdoutLoggerInterface::class, $logger);
        $this->app->instance('config', $config);

        $command = new ServerStartCommand($this->app);

        Application::getInstance()->setRunningInConsole(false);

        $result = $command->run(new ArrayInput([
            '--host' => '127.0.0.1',
            '--port' => '8001',
        ]), new NullOutput);

        $this->assertSame(0, $result);
    }

    public function testServeCommandOverridesHostAndPortForTheDefaultHttpServerType(): void
    {
        $serverConfig = [
            'servers' => [
                [
                    'name' => 'http',
                    'host' => '0.0.0.0',
                    'port' => 8000,
                ],
            ],
        ];

        $expectedServers = [
            [
                'name' => 'http',
                'host' => '127.0.0.1',
                'port' => 8001,
            ],
        ];

        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();
        $serverFactory->shouldReceive('configure')->once()->with(['servers' => $expectedServers]);
        $serverFactory->shouldReceive('start')->once();

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->once()->with('server')->andReturn($serverConfig);
        $config->shouldReceive('set')->once()->with('server.servers', $expectedServers);

        $dispatcher = m::mock(DispatcherContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);

        $this->app->instance(ServerFactory::class, $serverFactory);
        $this->app->instance('events', $dispatcher);
        $this->app->instance(StdoutLoggerInterface::class, $logger);
        $this->app->instance('config', $config);

        $command = new ServerStartCommand($this->app);

        Application::getInstance()->setRunningInConsole(false);

        $result = $command->run(new ArrayInput([
            '--host' => '127.0.0.1',
            '--port' => '8001',
        ]), new NullOutput);

        $this->assertSame(0, $result);
    }

    #[DataProvider('hostAndPortProvider')]
    public function testHostAndPortParsing(
        ?string $host,
        string $expectedHost,
        int $expectedPort,
        ?string $port = null,
        int $socketType = SWOOLE_SOCK_TCP,
        int $expectedSocketType = SWOOLE_SOCK_TCP,
    ): void {
        $server = [
            'name' => 'http',
            'host' => '0.0.0.0',
            'port' => 8123,
            'sock_type' => $socketType,
        ];
        config(['server' => ['servers' => [$server]]]);

        $expectedServer = array_replace($server, [
            'host' => $expectedHost,
            'port' => $expectedPort,
            'sock_type' => $expectedSocketType,
        ]);

        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();
        $serverFactory->expects('configure')->with(['servers' => [$expectedServer]]);
        $serverFactory->expects('start');
        $this->app->instance(ServerFactory::class, $serverFactory);

        $this->app->setRunningInConsole(false);

        $options = array_filter(['--host' => $host, '--port' => $port], static fn (?string $value): bool => $value !== null);
        $this->assertSame(0, (new ServerStartCommand($this->app))->run(new ArrayInput($options), new NullOutput));
        $this->assertSame([$expectedServer], config('server.servers'));
    }

    /**
     * Provide host and port options with their resolved server addresses.
     *
     * @return array<string, array{0: null|string, 1: string, 2: int, 3?: null|string, 4?: int, 5?: int}>
     */
    public static function hostAndPortProvider(): array
    {
        $cases = [
            'hostname with port' => ['localhost:8888', 'localhost', 8888],
            'IPv4 address with port' => ['127.0.0.1:8888', '127.0.0.1', 8888],
            'IPv6 address with port' => ['[::1]:8888', '::1', 8888, null, SWOOLE_SOCK_TCP, SWOOLE_SOCK_TCP6],
            'hostname without port' => ['localhost', 'localhost', 8123],
            'IPv6 address without port' => ['[::1]', '::1', 8123, null, SWOOLE_SOCK_TCP, SWOOLE_SOCK_TCP6],
            'explicit port wins' => ['[::1]:8888', '::1', 9000, '9000', SWOOLE_SOCK_TCP, SWOOLE_SOCK_TCP6],
            'bare IPv6 address' => ['::1', '::1', 8123, null, SWOOLE_SOCK_TCP, SWOOLE_SOCK_TCP6],
            'port without host' => [null, '0.0.0.0', 9000, '9000'],
            'IPv4 replaces IPv6 socket' => ['127.0.0.1', '127.0.0.1', 8123, null, SWOOLE_SOCK_TCP6],
            'hostname preserves socket family' => ['localhost', 'localhost', 8123, null, SWOOLE_SOCK_TCP6, SWOOLE_SOCK_TCP6],
        ];

        if (defined('SWOOLE_SSL')) {
            $cases['IPv6 preserves TLS'] = ['[::1]', '::1', 8123, null, SWOOLE_SOCK_TCP | SWOOLE_SSL, SWOOLE_SOCK_TCP6 | SWOOLE_SSL];
        }

        return $cases;
    }

    #[DataProvider('invalidServePorts')]
    public function testServeCommandRejectsInvalidPortOption(array $options): void
    {
        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->once()->with('server')->andReturn([
            'servers' => [
                [
                    'name' => 'http',
                    'type' => ServerInterface::SERVER_HTTP,
                    'host' => '0.0.0.0',
                    'port' => 8000,
                ],
            ],
        ]);

        $dispatcher = m::mock(DispatcherContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);

        $this->app->instance(ServerFactory::class, $serverFactory);
        $this->app->instance('events', $dispatcher);
        $this->app->instance(StdoutLoggerInterface::class, $logger);
        $this->app->instance('config', $config);

        $command = new ServerStartCommand($this->app);

        Application::getInstance()->setRunningInConsole(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The serve port must be an integer between 1 and 65535.');

        $command->run(new ArrayInput($options), new NullOutput);
    }

    /**
     * Get invalid serve ports.
     *
     * @return array<int, array{array<string, string>}>
     */
    public static function invalidServePorts(): array
    {
        return [
            [['--port' => 'not-a-port']],
            [['--port' => '0']],
            [['--port' => '-1']],
            [['--port' => '65536']],
            [['--host' => '127.0.0.1:not-a-port']],
            [['--host' => '[::1]:65536']],
        ];
    }

    public function testServeCommandRejectsAddressOptionsWithoutHttpServer(): void
    {
        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->once()->with('server')->andReturn([
            'servers' => [
                [
                    'name' => 'reverb',
                    'type' => ServerInterface::SERVER_WEBSOCKET,
                    'host' => '0.0.0.0',
                    'port' => 8080,
                ],
            ],
        ]);

        $dispatcher = m::mock(DispatcherContract::class);
        $logger = m::mock(StdoutLoggerInterface::class);

        $this->app->instance(ServerFactory::class, $serverFactory);
        $this->app->instance('events', $dispatcher);
        $this->app->instance(StdoutLoggerInterface::class, $logger);
        $this->app->instance('config', $config);

        $command = new ServerStartCommand($this->app);

        Application::getInstance()->setRunningInConsole(false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Cannot override server host or port because no HTTP server is configured.');

        $command->run(new ArrayInput(['--host' => '127.0.0.1']), new NullOutput);
    }
}
