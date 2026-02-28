<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Events;

use Hypervel\Console\Application;
use Hypervel\Console\Command;
use Hypervel\Console\Events\AfterExecute;
use Hypervel\Console\Events\AfterHandle;
use Hypervel\Console\Events\ArtisanStarting;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Console\Events\CommandFinished;
use Hypervel\Console\Events\CommandStarting;
use Hypervel\Console\Events\FailToHandle;
use Hypervel\Console\Events\ScheduledBackgroundTaskFinished;
use Hypervel\Console\Events\ScheduledTaskFailed;
use Hypervel\Console\Events\ScheduledTaskFinished;
use Hypervel\Console\Events\ScheduledTaskSkipped;
use Hypervel\Console\Events\ScheduledTaskStarting;
use Hypervel\Console\Scheduling\Event;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 * @coversNothing
 */
class EventsTest extends TestCase
{
    public function testArtisanStartingCarriesApplication()
    {
        $app = m::mock(Application::class);

        $event = new ArtisanStarting($app);

        $this->assertSame($app, $event->artisan);
    }

    public function testCommandStartingCarriesData()
    {
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $event = new CommandStarting('migrate', $input, $output);

        $this->assertSame('migrate', $event->command);
        $this->assertSame($input, $event->input);
        $this->assertSame($output, $event->output);
    }

    public function testCommandFinishedCarriesData()
    {
        $input = new ArrayInput([]);
        $output = new NullOutput();

        $event = new CommandFinished('migrate', $input, $output, 0);

        $this->assertSame('migrate', $event->command);
        $this->assertSame($input, $event->input);
        $this->assertSame($output, $event->output);
        $this->assertSame(0, $event->exitCode);
    }

    public function testCommandFinishedCarriesNonZeroExitCode()
    {
        $event = new CommandFinished('migrate', new ArrayInput([]), new NullOutput(), 1);

        $this->assertSame(1, $event->exitCode);
    }

    public function testBeforeHandleCarriesCommand()
    {
        $command = m::mock(Command::class);

        $event = new BeforeHandle($command);

        $this->assertSame($command, $event->getCommand());
    }

    public function testAfterHandleCarriesCommand()
    {
        $command = m::mock(Command::class);

        $event = new AfterHandle($command);

        $this->assertSame($command, $event->getCommand());
    }

    public function testFailToHandleCarriesCommandAndThrowable()
    {
        $command = m::mock(Command::class);
        $throwable = new RuntimeException('Command failed');

        $event = new FailToHandle($command, $throwable);

        $this->assertSame($command, $event->getCommand());
        $this->assertSame($throwable, $event->getThrowable());
    }

    public function testAfterExecuteCarriesCommandAndOptionalThrowable()
    {
        $command = m::mock(Command::class);

        $event = new AfterExecute($command);
        $this->assertSame($command, $event->getCommand());
        $this->assertNull($event->getThrowable());

        $throwable = new RuntimeException('Execute failed');
        $eventWithThrowable = new AfterExecute($command, $throwable);
        $this->assertSame($throwable, $eventWithThrowable->getThrowable());
    }

    public function testScheduledTaskStartingCarriesTask()
    {
        $task = new Event(m::mock(EventMutex::class), 'php foo');

        $event = new ScheduledTaskStarting($task);

        $this->assertSame($task, $event->task);
    }

    public function testScheduledTaskFinishedCarriesTaskAndRuntime()
    {
        $task = new Event(m::mock(EventMutex::class), 'php foo');

        $event = new ScheduledTaskFinished($task, 1.23);

        $this->assertSame($task, $event->task);
        $this->assertSame(1.23, $event->runtime);
    }

    public function testScheduledTaskSkippedCarriesTask()
    {
        $task = new Event(m::mock(EventMutex::class), 'php foo');

        $event = new ScheduledTaskSkipped($task);

        $this->assertSame($task, $event->task);
    }

    public function testScheduledTaskFailedCarriesTaskAndException()
    {
        $task = new Event(m::mock(EventMutex::class), 'php foo');
        $exception = new RuntimeException('Task failed');

        $event = new ScheduledTaskFailed($task, $exception);

        $this->assertSame($task, $event->task);
        $this->assertSame($exception, $event->exception);
    }

    public function testScheduledBackgroundTaskFinishedCarriesTask()
    {
        $task = new Event(m::mock(EventMutex::class), 'php foo');

        $event = new ScheduledBackgroundTaskFinished($task);

        $this->assertSame($task, $event->task);
    }
}
