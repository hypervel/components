<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Console\Events\CommandFinished;
use Hypervel\Console\Events\CommandStarting;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Foundation\Console\Kernel;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use ReflectionProperty;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;

class KernelEventReroutingTest extends TestCase
{
    public function testRerouteSymfonyCommandEventsWiresDispatcherToExistingArtisan(): void
    {
        $kernel = $this->app->make(KernelContract::class);

        // Force artisan to be created first (simulates test bootstrap).
        $kernel->getArtisan();

        // Now reroute — this must wire the dispatcher to the already-cached artisan.
        $kernel->rerouteSymfonyCommandEvents();

        $log = [];

        $this->app->make(Dispatcher::class)->listen(function (CommandStarting $event) use (&$log) {
            $log[] = 'starting:' . $event->command;
        });

        $this->app->make(Dispatcher::class)->listen(function (CommandFinished $event) use (&$log) {
            $log[] = 'finished:' . $event->command;
        });

        $kernel->registerCommand(new KernelEventReroutingTestCommand);
        $kernel->call('kernel-event-rerouting-test');

        $this->assertSame([
            'starting:kernel-event-rerouting-test',
            'finished:kernel-event-rerouting-test',
        ], $log);
    }

    public function testRerouteSymfonyCommandEventsWiresDispatcherBeforeArtisanCreated(): void
    {
        $kernel = $this->app->make(KernelContract::class);

        // Reroute BEFORE artisan is created — the dispatcher is stored and wired
        // later when getArtisan() constructs the application.
        $kernel->rerouteSymfonyCommandEvents();

        $log = [];

        $this->app->make(Dispatcher::class)->listen(function (CommandStarting $event) use (&$log) {
            $log[] = 'starting:' . $event->command;
        });

        $this->app->make(Dispatcher::class)->listen(function (CommandFinished $event) use (&$log) {
            $log[] = 'finished:' . $event->command;
        });

        $kernel->registerCommand(new KernelEventReroutingTestCommand);
        $kernel->call('kernel-event-rerouting-test');

        $this->assertSame([
            'starting:kernel-event-rerouting-test',
            'finished:kernel-event-rerouting-test',
        ], $log);
    }

    public function testReroutedEventsAreNotDispatchedWithoutListeners(): void
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->once()->with(CommandStarting::class)->andReturnFalse();
        $events->shouldReceive('hasListeners')->once()->with(CommandFinished::class)->andReturnFalse();
        $events->shouldNotReceive('dispatch');

        $kernel = new Kernel($this->app, $events);
        $kernel->rerouteSymfonyCommandEvents();

        $reflection = new ReflectionProperty($kernel, 'symfonyDispatcher');
        $dispatcher = $reflection->getValue($kernel);
        $this->assertInstanceOf(EventDispatcher::class, $dispatcher);

        $command = new SymfonyCommand('test');
        $input = new StringInput('test');
        $output = new BufferedOutput;

        $dispatcher->dispatch(new ConsoleCommandEvent($command, $input, $output), ConsoleEvents::COMMAND);
        $dispatcher->dispatch(new ConsoleTerminateEvent($command, $input, $output, 0), ConsoleEvents::TERMINATE);
    }
}

class KernelEventReroutingTestCommand extends Command
{
    protected ?string $signature = 'kernel-event-rerouting-test';

    public function handle(): void
    {
        // noop
    }
}
