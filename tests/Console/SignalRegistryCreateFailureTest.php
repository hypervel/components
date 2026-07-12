<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\SignalRegistry;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Support\ClassInvoker;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Swoole\Coroutine as SwooleCoroutine;

class SignalRegistryCreateFailureTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testPartialArrayRegistrationRollsBackHandlersAndWaiters(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 2]);

        SwooleCoroutine\run(function (): void {
            $registry = new SignalRegistry;

            try {
                $registry->register(
                    [SIGUSR1, SIGUSR2],
                    static fn (int $signal): null => null,
                );
                $this->fail('Expected the second waiter creation to fail.');
            } catch (CoroutineCreateException) {
                $invoker = new ClassInvoker($registry);

                $this->assertSame([], $invoker->signalHandlers);
                $this->assertSame([], $invoker->handling);
                $this->assertSame(1, SwooleCoroutine::stats()['coroutine_num']);
            }
        });
    }

    #[RunInSeparateProcess]
    public function testUnregisterTerminallyCancelsItsWaiterWithoutReporting(): void
    {
        SwooleCoroutine\run(function (): void {
            $handler = m::mock(ExceptionHandlerContract::class);
            $handler->shouldNotReceive('report');
            Container::getInstance()->instance(ExceptionHandlerContract::class, $handler);

            $registry = new SignalRegistry;
            $registry->register(SIGUSR1, static fn (int $signal): null => null);

            $invoker = new ClassInvoker($registry);
            $coroutineId = $invoker->handling[SIGUSR1];

            $this->assertTrue(Coroutine::exists($coroutineId));

            $registry->unregister(SIGUSR1);

            $this->assertFalse(Coroutine::exists($coroutineId));
            $this->assertSame([], $invoker->handling);
        });
    }
}
