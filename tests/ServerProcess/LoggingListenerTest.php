<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Hypervel\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\ServerProcess\AbstractProcess;
use Hypervel\ServerProcess\Events\AfterProcessHandle;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\ServerProcess\Listeners\LogAfterProcessStoppedListener;
use Hypervel\ServerProcess\Listeners\LogBeforeProcessStartListener;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class LoggingListenerTest extends TestCase
{
    use VerifiesDoubles;

    public function testLogBeforeProcessStartLogsViaStdoutLogger(): void
    {
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('info')->with('Process[my-worker.2] start.');

        $container = new Container;
        $container->instance(StdoutLoggerInterface::class, $logger);

        $listener = new LogBeforeProcessStartListener($container);
        $event = new BeforeProcessHandle($this->createProcess('my-worker'), 2);

        $listener->handle($event);
    }

    public function testLogAfterProcessStoppedLogsViaStdoutLogger(): void
    {
        $logger = Double::for(StdoutLoggerInterface::class)->strict();
        $logger->expects('info')->with('Process[scheduler.0] stopped.');

        $container = new Container;
        $container->instance(StdoutLoggerInterface::class, $logger);

        $listener = new LogAfterProcessStoppedListener($container);
        $event = new AfterProcessHandle($this->createProcess('scheduler'), 0);

        $listener->handle($event);
    }

    public function testLogBeforeProcessStartFallsBackToEchoWhenNoLogger(): void
    {
        $container = new Container;

        $listener = new LogBeforeProcessStartListener($container);
        $event = new BeforeProcessHandle($this->createProcess('queue'), 1);

        ob_start();
        $listener->handle($event);
        $output = ob_get_clean();

        $this->assertSame("Process[queue.1] start.\n", $output);
    }

    public function testLogAfterProcessStoppedFallsBackToEchoWhenNoLogger(): void
    {
        $container = new Container;

        $listener = new LogAfterProcessStoppedListener($container);
        $event = new AfterProcessHandle($this->createProcess('queue'), 1);

        ob_start();
        $listener->handle($event);
        $output = ob_get_clean();

        $this->assertSame("Process[queue.1] stopped.\n", $output);
    }

    /**
     * Create a named process without an event dispatcher.
     */
    private function createProcess(string $name): AbstractProcess
    {
        $process = new class(new Container) extends AbstractProcess {
            /**
             * Handle the test process.
             */
            public function handle(): void
            {
            }
        };
        $process->name = $name;

        return $process;
    }
}
