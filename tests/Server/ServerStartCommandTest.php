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
    protected function tearDown(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE');

        parent::tearDown();
    }

    public function testServeCommandFailsFastWhenRunningInConsoleIsTrue(): void
    {
        $command = new ServerStartCommand($this->app);

        Application::getInstance()->setRunningInConsole(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Error: APP_RUNNING_IN_CONSOLE is true. Your artisan binary may be outdated. Please update it so the serve and watch commands set APP_RUNNING_IN_CONSOLE=false before the server starts.');

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
                    'port' => 9501,
                ],
            ],
        ];

        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();
        $serverFactory->shouldReceive('configure')->once()->with($serverConfig);
        $serverFactory->shouldReceive('start')->once();

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->once()->with('server', [])->andReturn($serverConfig);

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
                    'port' => 9501,
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
                'port' => 9502,
            ],
        ];

        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();
        $serverFactory->shouldReceive('configure')->once()->with(['servers' => $expectedServers]);
        $serverFactory->shouldReceive('start')->once();

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->once()->with('server', [])->andReturn($serverConfig);
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
            '--port' => '9502',
        ]), new NullOutput);

        $this->assertSame(0, $result);
    }

    #[DataProvider('invalidServePorts')]
    public function testServeCommandRejectsInvalidPortOption(string $port): void
    {
        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->once()->with('server', [])->andReturn([
            'servers' => [
                [
                    'name' => 'http',
                    'type' => ServerInterface::SERVER_HTTP,
                    'host' => '0.0.0.0',
                    'port' => 9501,
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
        $this->expectExceptionMessage('The serve port must be an integer between 1 and 65535.');

        $command->run(new ArrayInput(['--port' => $port]), new NullOutput);
    }

    /**
     * Get invalid serve ports.
     *
     * @return array<int, array{string}>
     */
    public static function invalidServePorts(): array
    {
        return [
            ['not-a-port'],
            ['0'],
            ['-1'],
            ['65536'],
        ];
    }

    public function testServeCommandRejectsAddressOptionsWithoutHttpServer(): void
    {
        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->once()->with('server', [])->andReturn([
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
        $this->expectExceptionMessage('Cannot override server host or port because no HTTP server is configured.');

        $command->run(new ArrayInput(['--host' => '127.0.0.1']), new NullOutput);
    }
}
