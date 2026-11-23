<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\ServerProcess\Events\PipeMessage;
use Hypervel\ServerProcess\Exceptions\SocketAcceptException;
use Hypervel\Tests\ServerProcess\Fixtures\FakeSocket;
use Hypervel\Tests\ServerProcess\Fixtures\ListenableProcess;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use RuntimeException;

class ListenMethodTest extends TestCase
{
    use VerifiesDoubles;

    public function testListenerContinuesAfterSignalInterruption(): void
    {
        $dispatched = [];
        $dispatcher = Double::for(DispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(PipeMessage::class)->returns(true);
        $dispatcher->expects('dispatch')->resolves(function (object $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report')->with(Argument::type(SocketAcceptException::class));

        $process = new ListenableProcess($this->makeContainer($dispatcher, $handler));
        $socket = new FakeSocket([
            [false, SOCKET_EINTR],                     // 1st: signal interruption (transient)
            [serialize(['hello' => 'world']), 0],      // 2nd: valid data
        ]);
        $process->fakeSocket = $socket;

        $quit = new Channel(1);

        try {
            $process->callListen($quit);
            $this->waitUntil(function () use (&$dispatched, $socket): bool {
                return count($dispatched) === 1 && $socket->getCallCount() >= 2;
            });
        } finally {
            $this->stopListener($quit);
            $socket->close();
        }

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(PipeMessage::class, $dispatched[0]);
        $this->assertSame(['hello' => 'world'], $dispatched[0]->data);
        $this->assertGreaterThanOrEqual(2, $socket->getCallCount());
        $this->assertSame(1, $process->socketExports);
    }

    public function testListenerSkipsPipeMessageEventWithoutListeners(): void
    {
        $dispatcher = Double::for(DispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(PipeMessage::class)->returns(false);
        $dispatcher->expects('dispatch')->never();

        $process = new ListenableProcess($this->makeContainer($dispatcher));
        $socket = new FakeSocket([[serialize(['hello' => 'world']), 0]]);
        $process->fakeSocket = $socket;
        $quit = new Channel(1);

        try {
            $process->callListen($quit);
            $this->waitUntil(fn (): bool => $socket->getCallCount() >= 1);
        } finally {
            $this->stopListener($quit);
            $socket->close();
        }

        $this->assertSame(1, $socket->getCallCount());
    }

    public function testListenerContinuesAfterEagain(): void
    {
        $dispatched = [];
        $dispatcher = Double::for(DispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(PipeMessage::class)->returns(true);
        $dispatcher->expects('dispatch')->resolves(function (object $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report')->with(Argument::type(SocketAcceptException::class));

        $process = new ListenableProcess($this->makeContainer($dispatcher, $handler));
        $socket = new FakeSocket([
            [false, SOCKET_EAGAIN],                    // 1st: temporarily unavailable (transient)
            [serialize(['data' => 'value']), 0],       // 2nd: valid data
        ]);
        $process->fakeSocket = $socket;

        $quit = new Channel(1);

        try {
            $process->callListen($quit);
            $this->waitUntil(function () use (&$dispatched): bool {
                return count($dispatched) === 1;
            });
        } finally {
            $this->stopListener($quit);
            $socket->close();
        }

        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(PipeMessage::class, $dispatched[0]);
        $this->assertSame(['data' => 'value'], $dispatched[0]->data);
    }

    public function testListenerStopsOnPermanentSocketClosure(): void
    {
        $dispatcher = Double::for(DispatcherContract::class)->strict();
        $dispatcher->expects('dispatch')->never();

        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report')->with(Argument::type(SocketAcceptException::class));

        $process = new ListenableProcess($this->makeContainer($dispatcher, $handler));
        $socket = new FakeSocket([
            ['', 0],                                   // Permanent closure (empty string)
            [serialize(['should' => 'not reach']), 0], // Should never be called
        ]);
        $process->fakeSocket = $socket;

        $quit = new Channel(1);

        try {
            $process->callListen($quit);
            $this->waitUntil(fn (): bool => $quit->isClosing());
        } finally {
            $this->stopListener($quit);
            $socket->close();
        }

        $this->assertSame(1, $socket->getCallCount());
    }

    public function testListenerStopsOnConnectionReset(): void
    {
        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report')->with(Argument::type(SocketAcceptException::class));

        $process = new ListenableProcess($this->makeContainer(handler: $handler));
        $socket = new FakeSocket([
            [false, SOCKET_ECONNRESET],                // Permanent error
            [serialize(['should' => 'not reach']), 0], // Should never be called
        ]);
        $process->fakeSocket = $socket;

        $quit = new Channel(1);

        try {
            $process->callListen($quit);
            $this->waitUntil(fn (): bool => $quit->isClosing());
        } finally {
            $this->stopListener($quit);
            $socket->close();
        }

        $this->assertSame(1, $socket->getCallCount());
    }

    public function testListenerStopsOnBadFileDescriptor(): void
    {
        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report')->with(Argument::type(SocketAcceptException::class));

        $process = new ListenableProcess($this->makeContainer(handler: $handler));
        $socket = new FakeSocket([
            [false, SOCKET_EBADF],                     // Permanent error
            [serialize(['should' => 'not reach']), 0], // Should never be called
        ]);
        $process->fakeSocket = $socket;

        $quit = new Channel(1);

        try {
            $process->callListen($quit);
            $this->waitUntil(fn (): bool => $quit->isClosing());
        } finally {
            $this->stopListener($quit);
            $socket->close();
        }

        $this->assertSame(1, $socket->getCallCount());
    }

    public function testListenerStopsWhenTheProcessSocketCannotBeExported(): void
    {
        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report')
            ->with(Argument::satisfies(fn (SocketAcceptException $exception): bool => $exception->isPermanent()
                && $exception->getMessage() === 'Unable to export process IPC socket'));

        $process = new ListenableProcess($this->makeContainer(handler: $handler));
        $process->fakeSocket = false;
        $quit = new Channel(1);

        try {
            $process->callListen($quit);
            $this->waitUntil(fn (): bool => $quit->isClosing());
        } finally {
            $this->stopListener($quit);
        }

        $this->assertSame(1, $process->socketExports);
    }

    public function testListenerClosesItsChannelWhenTheExceptionReporterThrows(): void
    {
        Coroutine::reportExceptions(false);
        $reporterFailure = new RuntimeException('reporter failed');
        $handler = Double::for(ExceptionHandlerContract::class)->strict();
        $handler->expects('report')
            ->with(Argument::type(SocketAcceptException::class))
            ->throws($reporterFailure);

        $process = new ListenableProcess($this->makeContainer(handler: $handler));
        $socket = new FakeSocket([['', 0]]);
        $process->fakeSocket = $socket;
        $quit = new Channel(1);

        try {
            $process->callListen($quit);
            $this->waitUntil(fn (): bool => $quit->isClosing());
        } finally {
            $this->stopListener($quit);
            $socket->close();
        }

        $this->assertTrue($quit->isClosing());
    }

    public function testListenerDispatchesEverySerializableFalsyPayload(): void
    {
        $payloads = [false, null, 0, '', []];
        $dispatched = [];
        $dispatcher = Double::for(DispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->times(count($payloads))->with(PipeMessage::class)->returns(true);
        $dispatcher->expects('dispatch')->times(count($payloads))->resolves(function (PipeMessage $event) use (&$dispatched): void {
            $dispatched[] = $event->data;
        });

        $process = new ListenableProcess($this->makeContainer($dispatcher));
        $socket = new FakeSocket(array_map(
            static fn (mixed $payload): array => [serialize($payload), 0],
            $payloads,
        ));
        $process->fakeSocket = $socket;
        $quit = new Channel(1);

        try {
            $process->callListen($quit);
            $this->waitUntil(function () use (&$dispatched, $payloads): bool {
                return count($dispatched) === count($payloads);
            });
        } finally {
            $this->stopListener($quit);
            $socket->close();
        }

        $this->assertSame($payloads, $dispatched);
    }

    /**
     * Create a container for the listener fixture.
     */
    private function makeContainer(
        ?DispatcherContract $dispatcher = null,
        ?ExceptionHandlerContract $handler = null,
    ): Container {
        $container = new Container;

        if ($dispatcher !== null) {
            $container->instance('events', $dispatcher);
        }

        if ($handler !== null) {
            $container->instance(ExceptionHandlerContract::class, $handler);
        }

        return $container;
    }

    /**
     * Stop the listener and wait for it to release its channel.
     */
    private function stopListener(Channel $quit): void
    {
        if (! $quit->isClosing()) {
            $quit->push(true);
        }

        $this->waitUntil(fn (): bool => $quit->isClosing());
    }

    /**
     * Wait for a condition using a monotonic deadline.
     */
    private function waitUntil(Closure $condition, float $timeout = 1.0): void
    {
        $deadline = hrtime(true) + (int) ($timeout * 1e9);

        while (hrtime(true) < $deadline) {
            if ($condition()) {
                return;
            }

            usleep(1_000);
        }

        $this->fail('Condition was not met before the deadline.');
    }
}
