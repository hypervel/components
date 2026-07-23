<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Console;

use Hypervel\Console\OutputStyle;
use Hypervel\Console\View\Components\Factory;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Foundation\Application;
use Hypervel\Server\ServerFactory;
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

class ServeCommandTest extends TestCase
{
    private const WORKING_PATH_ENV = 'TESTBENCH_WORKING_PATH';

    /** @var array{process: false|string, environment_exists: bool, environment: mixed, server_exists: bool, server: mixed} */
    private array $workingPathState;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workingPathState = [
            'process' => getenv(self::WORKING_PATH_ENV),
            'environment_exists' => array_key_exists(self::WORKING_PATH_ENV, $_ENV),
            'environment' => $_ENV[self::WORKING_PATH_ENV] ?? null,
            'server_exists' => array_key_exists(self::WORKING_PATH_ENV, $_SERVER),
            'server' => $_SERVER[self::WORKING_PATH_ENV] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        try {
            $processValue = $this->workingPathState['process'];
            putenv($processValue === false
                ? self::WORKING_PATH_ENV
                : self::WORKING_PATH_ENV . "={$processValue}");

            if ($this->workingPathState['environment_exists']) {
                $_ENV[self::WORKING_PATH_ENV] = $this->workingPathState['environment'];
            } else {
                unset($_ENV[self::WORKING_PATH_ENV]);
            }

            if ($this->workingPathState['server_exists']) {
                $_SERVER[self::WORKING_PATH_ENV] = $this->workingPathState['server'];
            } else {
                unset($_SERVER[self::WORKING_PATH_ENV]);
            }
        } finally {
            parent::tearDown();
        }
    }

    #[Test]
    public function itStartsTheUnderlyingServerCommandAndDispatchesLifecycleEvents(): void
    {
        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();
        $serverFactory->shouldReceive('configure')->once()->with(['http' => ['port' => 8000]]);
        $serverFactory->shouldReceive('start')->once();

        $config = m::mock(Repository::class);
        $config->shouldReceive('array')->once()->with('server')->andReturn(['http' => ['port' => 8000]]);

        $logger = m::mock(StdoutLoggerInterface::class);

        $this->app->instance(ServerFactory::class, $serverFactory);
        $this->app->instance(StdoutLoggerInterface::class, $logger);
        $this->app->instance('config', $config);

        $startedEvents = [];
        $endedEvents = [];

        $this->app->make('events')->listen(ServeCommandStarted::class, static function (ServeCommandStarted $event) use (&$startedEvents): void {
            $startedEvents[] = $event;
        });

        $this->app->make('events')->listen(ServeCommandEnded::class, static function (ServeCommandEnded $event) use (&$endedEvents): void {
            $endedEvents[] = $event;
        });

        $command = new ServeCommand($this->app);

        Application::getInstance()->setRunningInConsole(false);

        $result = $command->run(new ArrayInput([]), new NullOutput);

        $this->assertSame(0, $result);
        $this->assertCount(1, $startedEvents);
        $this->assertCount(1, $endedEvents);
        $this->assertSame(0, $endedEvents[0]->exitCode);
        $this->assertInstanceOf(OutputStyle::class, $startedEvents[0]->output);
        $this->assertInstanceOf(Factory::class, $startedEvents[0]->components);
        $this->assertSame(package_path(), getenv('TESTBENCH_WORKING_PATH'));
        $this->assertSame(package_path(), $_ENV['TESTBENCH_WORKING_PATH']);
        $this->assertSame(package_path(), $_SERVER['TESTBENCH_WORKING_PATH']);
    }

    #[Test]
    public function itDispatchesAFailureEndedEventWhenTheUnderlyingServeGuardFails(): void
    {
        $startedEvents = [];
        $endedEvents = [];

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
}
