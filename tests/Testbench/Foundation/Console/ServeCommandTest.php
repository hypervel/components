<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Composer\Config as ComposerConfig;
use Composer\Util\ProcessExecutor;
use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Server\ServerFactory;
use Hypervel\Server\ServerInterface;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Console\ServeCommand;
use Hypervel\Testbench\Foundation\Events\ServeCommandEnded;
use Hypervel\Testbench\Foundation\Events\ServeCommandStarted;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

use function Hypervel\Testbench\package_path;
use function Hypervel\Testbench\workbench_relative_path;

class ServeCommandTest extends TestCase
{
    private const array ENVIRONMENT_VARIABLES = ['TESTBENCH_WORKING_PATH', 'SERVER_HOST'];

    /** @var array<string, array{process: false|string, environment_exists: bool, environment: mixed, server_exists: bool, server: mixed}> */
    private array $environmentState = [];

    private int $processTimeout;

    private Filesystem $filesystem;

    /**
     * Capture the working environment and Composer timeout.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->processTimeout = ProcessExecutor::getTimeout();

        foreach (self::ENVIRONMENT_VARIABLES as $name) {
            $this->environmentState[$name] = [
                'process' => getenv($name),
                'environment_exists' => array_key_exists($name, $_ENV),
                'environment' => $_ENV[$name] ?? null,
                'server_exists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
            ];
        }

        // The serve command reads SERVER_HOST for its default host when it is created.
        $this->setServerHost(null);
    }

    /**
     * Restore the working environment and Composer timeout.
     */
    protected function tearDown(): void
    {
        try {
            foreach ($this->environmentState as $name => $state) {
                putenv($state['process'] === false ? $name : "{$name}={$state['process']}");

                if ($state['environment_exists']) {
                    $_ENV[$name] = $state['environment'];
                } else {
                    unset($_ENV[$name]);
                }

                if ($state['server_exists']) {
                    $_SERVER[$name] = $state['server'];
                } else {
                    unset($_SERVER[$name]);
                }
            }

            $this->removeSyncPaths();
        } finally {
            ProcessExecutor::setTimeout($this->processTimeout);

            parent::tearDown();
        }
    }

    #[Test]
    public function itStartsTheUnderlyingServerCommandAndDispatchesLifecycleEvents(): void
    {
        $linkedWhileServing = false;

        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();
        $serverFactory->shouldReceive('configure')->once();
        $serverFactory->shouldReceive('start')->once()->andReturnUsing(function () use (&$linkedWhileServing): void {
            $linkedWhileServing = is_link($this->syncLinkPath());
        });

        $this->bindServer($serverFactory);
        $this->app->instance(ConfigContract::class, $this->syncConfiguration());

        $startedEvents = [];
        $endedEvents = [];
        $linkedWhenStarted = null;

        $this->app->make('events')->listen(ServeCommandStarted::class, function (ServeCommandStarted $event) use (&$startedEvents, &$linkedWhenStarted): void {
            $startedEvents[] = $event;
            $linkedWhenStarted = is_link($this->syncLinkPath());
        });

        $this->app->make('events')->listen(ServeCommandEnded::class, static function (ServeCommandEnded $event) use (&$endedEvents): void {
            $endedEvents[] = $event;
        });

        $command = new ServeCommand($this->app);

        Application::getInstance()->setRunningInConsole(false);

        class_exists(ComposerConfig::class);
        ProcessExecutor::setTimeout(300);

        $result = $command->run(new ArrayInput([]), new NullOutput);

        $this->assertSame(0, $result);
        $this->assertSame(0, ProcessExecutor::getTimeout());
        $this->assertCount(1, $startedEvents);
        $this->assertCount(1, $endedEvents);
        $this->assertSame(0, $endedEvents[0]->exitCode);
        $this->assertInstanceOf(OutputStyle::class, $startedEvents[0]->output);
        $this->assertInstanceOf(Factory::class, $startedEvents[0]->components);
        $this->assertSame(package_path(), getenv('TESTBENCH_WORKING_PATH'));
        $this->assertSame(package_path(), $_ENV['TESTBENCH_WORKING_PATH']);
        $this->assertSame(package_path(), $_SERVER['TESTBENCH_WORKING_PATH']);
        $this->assertFalse($linkedWhenStarted);
        $this->assertTrue($linkedWhileServing);
        $this->assertFalse(is_link($this->syncLinkPath()));
    }

    #[Test]
    public function passiveObserversDoNotCauseServeLifecycleEventsToDispatch(): void
    {
        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();
        $serverFactory->shouldReceive('configure')->once();
        $serverFactory->shouldReceive('start')->once();

        $this->bindServer($serverFactory);
        $this->app->instance(ConfigContract::class, new Config);

        $observedEvents = [];
        $events = $this->app->make(Dispatcher::class);
        $events->observe(
            ServeCommandStarted::class,
            static function (ServeCommandStarted $event) use (&$observedEvents): void {
                $observedEvents[] = $event;
            }
        );
        $events->observe(
            ServeCommandEnded::class,
            static function (ServeCommandEnded $event) use (&$observedEvents): void {
                $observedEvents[] = $event;
            }
        );

        Application::getInstance()->setRunningInConsole(false);

        $this->assertSame(0, (new ServeCommand($this->app))->run(new ArrayInput([]), new NullOutput));
        $this->assertSame([], $observedEvents);
    }

    #[Test]
    public function itListensOnTheLoopbackAddressUnlessAnotherHostIsGiven(): void
    {
        $this->assertSame('127.0.0.1', $this->servedHost([]));
        $this->assertSame('192.0.2.10', $this->servedHost(['--host' => '192.0.2.10']));

        $this->setServerHost('0.0.0.0');

        $this->assertSame('0.0.0.0', $this->servedHost([]));
    }

    #[Test]
    public function itDispatchesAFailureEndedEventWhenTheUnderlyingServeGuardFails(): void
    {
        $startedEvents = [];
        $endedEvents = [];

        $this->app->instance(ConfigContract::class, new Config);

        $this->app->make('events')->listen(ServeCommandStarted::class, static function (ServeCommandStarted $event) use (&$startedEvents): void {
            $startedEvents[] = $event;
        });

        $this->app->make('events')->listen(ServeCommandEnded::class, static function (ServeCommandEnded $event) use (&$endedEvents): void {
            $endedEvents[] = $event;
        });

        $command = new ServeCommand($this->app);

        Application::getInstance()->setRunningInConsole(true);

        try {
            $command->run(new ArrayInput([]), new NullOutput);
            $this->fail('ServeCommand should rethrow the underlying RuntimeException when the server bootstrap guard fails.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('APP_RUNNING_IN_CONSOLE is true', $exception->getMessage());
        }

        $this->assertCount(1, $startedEvents);
        $this->assertCount(1, $endedEvents);
        $this->assertSame(ServeCommand::FAILURE, $endedEvents[0]->exitCode);
    }

    #[Test]
    public function itRemovesSyncLinksAndRethrowsWhenTheServerFailsToStart(): void
    {
        $failure = new RuntimeException('Unable to bind the server.');
        $linkedWhenFailing = false;

        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();
        $serverFactory->shouldReceive('configure')->once();
        $serverFactory->shouldReceive('start')->once()->andReturnUsing(function () use ($failure, &$linkedWhenFailing): never {
            $linkedWhenFailing = is_link($this->syncLinkPath());

            throw $failure;
        });

        $this->bindServer($serverFactory);
        $this->app->instance(ConfigContract::class, $this->syncConfiguration());

        $endedEvents = [];

        $this->app->make('events')->listen(ServeCommandEnded::class, static function (ServeCommandEnded $event) use (&$endedEvents): void {
            $endedEvents[] = $event;
        });

        Application::getInstance()->setRunningInConsole(false);

        try {
            (new ServeCommand($this->app))->run(new ArrayInput([]), new NullOutput);
            $this->fail('ServeCommand should rethrow the server start failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertTrue($linkedWhenFailing);
        $this->assertFalse(is_link($this->syncLinkPath()));
        $this->assertCount(1, $endedEvents);
        $this->assertSame(ServeCommand::FAILURE, $endedEvents[0]->exitCode);
    }

    #[Test]
    public function itRemovesCreatedSyncLinksWhenALaterSyncEntryFails(): void
    {
        $occupiedPath = $this->app->basePath('public/serve-command-occupied');
        $this->filesystem->put($occupiedPath, 'existing');
        $this->filesystem->put($this->backupPath($occupiedPath), 'existing backup');

        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldNotReceive('start');

        $this->app->instance(ServerFactory::class, $serverFactory);
        $this->app->instance(ConfigContract::class, new Config([
            'workbench' => [
                'sync' => [
                    ['from' => workbench_relative_path('resources'), 'to' => 'public/serve-command-assets'],
                    ['from' => workbench_relative_path('resources'), 'to' => 'public/serve-command-occupied'],
                ],
            ],
        ]));

        $endedEvents = [];

        $this->app->make('events')->listen(ServeCommandEnded::class, static function (ServeCommandEnded $event) use (&$endedEvents): void {
            $endedEvents[] = $event;
        });

        Application::getInstance()->setRunningInConsole(false);

        try {
            (new ServeCommand($this->app))->run(new ArrayInput([]), new NullOutput);
            $this->fail('ServeCommand should rethrow the sync failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                "Unable to back up [{$occupiedPath}] because [{$this->backupPath($occupiedPath)}] already exists.",
                $exception->getMessage(),
            );
        }

        $this->assertFalse(is_link($this->syncLinkPath()));
        $this->assertSame('existing', $this->filesystem->get($occupiedPath));
        $this->assertCount(1, $endedEvents);
        $this->assertSame(ServeCommand::FAILURE, $endedEvents[0]->exitCode);
    }

    #[Test]
    public function itKeepsTheFirstFailureWhenEndingServeAlsoFails(): void
    {
        $startedFailure = new RuntimeException('Started listener failed.');

        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldNotReceive('start');

        $this->app->instance(ServerFactory::class, $serverFactory);
        $this->app->instance(ConfigContract::class, $this->syncConfiguration());

        $events = $this->app->make('events');
        $events->listen(ServeCommandStarted::class, static function () use ($startedFailure): never {
            throw $startedFailure;
        });
        $events->listen(ServeCommandEnded::class, static function (): never {
            throw new RuntimeException('Ended listener failed.');
        });

        Application::getInstance()->setRunningInConsole(false);

        try {
            (new ServeCommand($this->app))->run(new ArrayInput([]), new NullOutput);
            $this->fail('ServeCommand should rethrow the started listener failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame($startedFailure, $exception);
        }

        $this->assertFalse(is_link($this->syncLinkPath()));
    }

    /**
     * Bind the server factory and an HTTP server configured to listen on every interface.
     */
    private function bindServer(ServerFactory $serverFactory): void
    {
        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->once()->with('server')->andReturn([
            'servers' => [
                ['name' => 'http', 'type' => ServerInterface::SERVER_HTTP, 'host' => '0.0.0.0', 'port' => 9501],
            ],
        ]);
        $config->shouldReceive('set')->once()->with('server.servers', m::type('array'));

        $this->app->instance(ServerFactory::class, $serverFactory);
        $this->app->instance(StdoutLoggerInterface::class, m::mock(StdoutLoggerInterface::class));
        $this->app->instance('config', $config);
    }

    /**
     * Run the serve command and return the host the HTTP server was configured with.
     *
     * @param array<string, string> $input
     */
    private function servedHost(array $input): string
    {
        $host = null;

        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();
        $serverFactory->shouldReceive('configure')->once()->andReturnUsing(static function (array $config) use (&$host): void {
            $host = $config['servers'][0]['host'];
        });
        $serverFactory->shouldReceive('start')->once();

        $this->bindServer($serverFactory);
        $this->app->instance(ConfigContract::class, new Config);

        Application::getInstance()->setRunningInConsole(false);

        $this->assertSame(0, (new ServeCommand($this->app))->run(new ArrayInput($input), new NullOutput));

        return $host;
    }

    /**
     * Set or clear the SERVER_HOST environment variable.
     */
    private function setServerHost(?string $host): void
    {
        if ($host === null) {
            putenv('SERVER_HOST');
            unset($_ENV['SERVER_HOST'], $_SERVER['SERVER_HOST']);

            return;
        }

        putenv("SERVER_HOST={$host}");
        $_ENV['SERVER_HOST'] = $host;
        $_SERVER['SERVER_HOST'] = $host;
    }

    /**
     * Get a configuration that syncs Workbench resources into the runtime skeleton.
     */
    private function syncConfiguration(): ConfigContract
    {
        return new Config([
            'workbench' => [
                'sync' => [
                    ['from' => workbench_relative_path('resources'), 'to' => 'public/serve-command-assets'],
                ],
            ],
        ]);
    }

    /**
     * Get the sync link created inside the runtime skeleton.
     */
    private function syncLinkPath(): string
    {
        return $this->app->basePath('public/serve-command-assets');
    }

    /**
     * Get the backup path the sync action uses for an existing destination.
     */
    private function backupPath(string $path): string
    {
        return dirname($path) . '/.' . basename($path) . '.backup';
    }

    /**
     * Remove every runtime path the sync tests may create.
     */
    private function removeSyncPaths(): void
    {
        $occupiedPath = $this->app->basePath('public/serve-command-occupied');

        foreach ([$this->syncLinkPath(), $occupiedPath, $this->backupPath($occupiedPath)] as $path) {
            if (is_link($path) || is_file($path)) {
                $this->filesystem->delete($path);
            }
        }
    }
}
