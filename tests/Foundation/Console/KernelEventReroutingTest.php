<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Console\Events\CommandFinished;
use Hypervel\Console\Events\CommandStarting;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class KernelEventReroutingTest extends TestCase
{
    public function testRerouteSymfonyCommandEventsWiresDispatcherToExistingArtisan()
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

        $kernel->registerCommand(new KernelEventReroutingTestCommand());
        $kernel->call('kernel-event-rerouting-test');

        $this->assertSame([
            'starting:kernel-event-rerouting-test',
            'finished:kernel-event-rerouting-test',
        ], $log);
    }

    public function testRerouteSymfonyCommandEventsWiresDispatcherBeforeArtisanCreated()
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

        $kernel->registerCommand(new KernelEventReroutingTestCommand());
        $kernel->call('kernel-event-rerouting-test');

        $this->assertSame([
            'starting:kernel-event-rerouting-test',
            'finished:kernel-event-rerouting-test',
        ], $log);
    }
}

class KernelEventReroutingTestCommand extends Command
{
    protected ?string $signature = 'kernel-event-rerouting-test';

    public function handle()
    {
        // noop
    }
}
