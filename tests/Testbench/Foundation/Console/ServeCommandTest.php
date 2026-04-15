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
    protected function tearDown(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE');
        putenv('TESTBENCH_WORKING_PATH');

        unset(
            $_ENV['APP_RUNNING_IN_CONSOLE'],
            $_SERVER['APP_RUNNING_IN_CONSOLE'],
            $_ENV['TESTBENCH_WORKING_PATH'],
            $_SERVER['TESTBENCH_WORKING_PATH'],
        );

        parent::tearDown();
    }

    #[Test]
    public function itStartsTheUnderlyingServerCommandAndDispatchesLifecycleEvents(): void
    {
        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('setEventDispatcher')->once()->andReturnSelf();
        $serverFactory->shouldReceive('setLogger')->once()->andReturnSelf();
        $serverFactory->shouldReceive('configure')->once()->with(['http' => ['port' => 9501]]);
        $serverFactory->shouldReceive('start')->once();

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')->once()->with('server', [])->andReturn(['http' => ['port' => 9501]]);

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
