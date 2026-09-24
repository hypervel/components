<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\SignalRegistry;
use Hypervel\Engine\Channel;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use stdClass;

class SignalRegistryTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testRegister(): void
    {
        $registry = new SignalRegistry;
        $state = null;
        $handled = new Channel(1);

        try {
            $registry->register($this, SIGWINCH, function () use (&$state, $handled): void {
                $state .= 'otwell';
                $handled->push(true);
            });
            $registry->register($this, SIGWINCH, function () use (&$state): void {
                $state = 'taylor';
            });

            for ($delivery = 0; $delivery < 2; ++$delivery) {
                posix_kill(posix_getpid(), SIGWINCH);
                $this->assertTrue($handled->pop(1));
                $this->assertSame('taylorotwell', $state);
                usleep(5000);
            }
        } finally {
            $registry->unregister($this);
            $handled->close();
        }
    }

    #[RunInSeparateProcess]
    public function testUnregister(): void
    {
        $registry = new SignalRegistry;
        $state = null;

        try {
            $registry->register($this, SIGWINCH, function () use (&$state): void {
                $state .= 'otwell';
            });
            $registry->register($this, SIGWINCH, function () use (&$state): void {
                $state = 'taylor';
            });
            $registry->unregister($this);

            posix_kill(posix_getpid(), SIGWINCH);
            usleep(5000);
            $this->assertNull($state);
        } finally {
            $registry->unregister($this);
        }
    }

    #[RunInSeparateProcess]
    public function testRegistrationDuringDispatchUsesTheExistingListener(): void
    {
        $registry = new SignalRegistry;
        $nextOwner = new stdClass;
        $handled = new Channel(1);
        $coroutinesBefore = Coroutine::stats()['coroutine_num'];

        try {
            $registry->register($this, SIGWINCH, function () use ($registry, $nextOwner, $handled): void {
                $registry->unregister($this);
                $registry->register($nextOwner, SIGWINCH, fn () => $handled->push('next'));
                $handled->push('first');
            });

            posix_kill(posix_getpid(), SIGWINCH);
            $this->assertSame('first', $handled->pop(1));
            usleep(5000);
            $this->assertSame($coroutinesBefore + 1, Coroutine::stats()['coroutine_num']);

            posix_kill(posix_getpid(), SIGWINCH);
            $this->assertSame('next', $handled->pop(1));
            usleep(5000);
        } finally {
            $registry->unregister($this);
            $registry->unregister($nextOwner);
            $handled->close();
        }

        $this->assertSame($coroutinesBefore, Coroutine::stats()['coroutine_num']);
    }

    #[RunInSeparateProcess]
    public function testUnregisteringTheLastOwnerDuringDispatchDoesNotRearm(): void
    {
        $registry = new SignalRegistry;
        $handled = new Channel(1);
        $coroutinesBefore = Coroutine::stats()['coroutine_num'];

        try {
            $registry->register($this, SIGWINCH, function () use ($registry, $handled): void {
                $registry->unregister($this);
                usleep(1000);
                $handled->push(true);
            });

            posix_kill(posix_getpid(), SIGWINCH);
            $this->assertTrue($handled->pop(1));
            usleep(5000);
            $this->assertSame($coroutinesBefore, Coroutine::stats()['coroutine_num']);
        } finally {
            $registry->unregister($this);
            $handled->close();
        }
    }

    #[RunInSeparateProcess]
    public function testInterleavedOwnersRetainOrderAndIsolateCallbackFailuresAndContext(): void
    {
        $registry = new SignalRegistry;
        $otherOwner = new stdClass;
        $handled = new Channel(1);
        $trace = [];
        $exception = new RuntimeException('Signal callback failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->expects('report')->twice()->with($exception);
        Container::getInstance()->instance(ExceptionHandler::class, $handler);

        try {
            $registry->register($this, SIGWINCH, function () use (&$trace, $handled): void {
                $trace[] = ['first', CoroutineContext::has('signal.test')];
                $handled->push(true);
            });
            $registry->register($otherOwner, SIGWINCH, function () use (&$trace, $exception): never {
                $trace[] = ['other', CoroutineContext::has('signal.test')];
                throw $exception;
            });
            $registry->register($this, SIGWINCH, function () use (&$trace): void {
                $trace[] = ['last', CoroutineContext::has('signal.test')];
                CoroutineContext::set('signal.test', true);
            });

            for ($delivery = 0; $delivery < 2; ++$delivery) {
                posix_kill(posix_getpid(), SIGWINCH);
                $this->assertTrue($handled->pop(1));
                usleep(5000);
            }

            $this->assertSame([
                ['last', false], ['other', false], ['first', false],
                ['last', false], ['other', false], ['first', false],
            ], $trace);
        } finally {
            $registry->unregister($this);
            $registry->unregister($otherOwner);
            $handled->close();
        }
    }

    public function testRegisterSingleSignalPushesHandler(): void
    {
        $registry = m::mock(SignalRegistry::class)->makePartial();
        $registry->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('waitSignal')->with(SIGTERM)->once();

        $callback = fn (int $signo) => null;
        $registry->register($this, SIGTERM, $callback);

        $invoker = new ClassInvoker($registry);
        $this->assertArrayHasKey(SIGTERM, $invoker->signalHandlers);
        $this->assertCount(1, $invoker->signalHandlers[SIGTERM]);
        $this->assertSame(['owner' => $this, 'callback' => $callback], $invoker->signalHandlers[SIGTERM][0]);
    }

    public function testRegisterMultipleSignalsRegistersEach(): void
    {
        $registry = m::mock(SignalRegistry::class)->makePartial();
        $registry->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('waitSignal')->with(SIGTERM)->once();
        $registry->shouldReceive('waitSignal')->with(SIGINT)->once();

        $callback = fn (int $signo) => null;
        $registry->register($this, [SIGTERM, SIGINT], $callback);

        $invoker = new ClassInvoker($registry);
        $this->assertArrayHasKey(SIGTERM, $invoker->signalHandlers);
        $this->assertArrayHasKey(SIGINT, $invoker->signalHandlers);
        $this->assertSame($callback, $invoker->signalHandlers[SIGTERM][0]['callback']);
        $this->assertSame($callback, $invoker->signalHandlers[SIGINT][1]['callback']);
    }

    public function testRegisterMultipleHandlersForSameSignal(): void
    {
        $registry = m::mock(SignalRegistry::class)->makePartial();
        $registry->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('waitSignal')->with(SIGTERM)->twice();

        $callbackA = fn (int $signo) => null;
        $callbackB = fn (int $signo) => null;

        $registry->register($this, SIGTERM, $callbackA);
        $registry->register($this, SIGTERM, $callbackB);

        $invoker = new ClassInvoker($registry);
        $this->assertCount(2, $invoker->signalHandlers[SIGTERM]);
        $this->assertSame($callbackA, $invoker->signalHandlers[SIGTERM][0]['callback']);
        $this->assertSame($callbackB, $invoker->signalHandlers[SIGTERM][1]['callback']);
    }

    public function testUnregisterSingleSignal(): void
    {
        $registry = m::mock(SignalRegistry::class)->makePartial();
        $registry->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('waitSignal');

        $registry->register($this, SIGTERM, fn (int $signo) => null);
        $registry->register($this, SIGINT, fn (int $signo) => null);

        $registry->unregister($this, SIGTERM);

        $invoker = new ClassInvoker($registry);
        $this->assertArrayNotHasKey(SIGTERM, $invoker->signalHandlers);
        $this->assertCount(1, $invoker->signalHandlers[SIGINT]);
    }

    public function testUnregisterMultipleSignals(): void
    {
        $registry = m::mock(SignalRegistry::class)->makePartial();
        $registry->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('waitSignal');

        $registry->register($this, SIGTERM, fn (int $signo) => null);
        $registry->register($this, SIGINT, fn (int $signo) => null);

        $registry->unregister($this, [SIGTERM, SIGINT]);

        $invoker = new ClassInvoker($registry);
        $this->assertSame([], $invoker->signalHandlers);
    }

    public function testUnregisterAllSignals(): void
    {
        $registry = m::mock(SignalRegistry::class)->makePartial();
        $registry->shouldAllowMockingProtectedMethods();
        $registry->shouldReceive('waitSignal');

        $registry->register($this, SIGTERM, fn (int $signo) => null);
        $registry->register($this, SIGINT, fn (int $signo) => null);

        $registry->unregister($this);

        $invoker = new ClassInvoker($registry);
        $this->assertEmpty($invoker->signalHandlers);
    }

    public function testUnregisterCancelsWaitingCoroutines(): void
    {
        $registry = new SignalRegistry;
        $invoker = new ClassInvoker($registry);

        try {
            $registry->register($this, [SIGTERM, SIGINT], fn () => null);
            $coroutineIds = $invoker->handling;
            $registry->unregister($this, SIGTERM);
            $this->assertFalse(Coroutine::exists($coroutineIds[SIGTERM]));
            $this->assertTrue(Coroutine::exists($coroutineIds[SIGINT]));

            $registry->unregister($this);
            $this->assertFalse(Coroutine::exists($coroutineIds[SIGINT]));
            $this->assertSame([], $invoker->handling);
        } finally {
            $registry->unregister($this);
        }
    }

    public function testWaitSignalOnlySpawnsOneCoroutinePerSignal(): void
    {
        $registry = new SignalRegistry;
        $coroutinesBefore = Coroutine::stats()['coroutine_num'];

        try {
            $registry->register($this, SIGTERM, fn () => null);
            $registry->register($this, SIGTERM, fn () => null);
            $this->assertSame($coroutinesBefore + 1, Coroutine::stats()['coroutine_num']);
        } finally {
            $registry->unregister($this);
        }
    }
}
