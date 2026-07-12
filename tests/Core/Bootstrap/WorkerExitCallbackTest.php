<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Bootstrap;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Core\Bootstrap\WorkerExitCallback;
use Hypervel\Core\Events\OnWorkerExit;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Server;

class WorkerExitCallbackTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testWorkerExitDoesNotRequireAnotherCoroutineSlot(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);

        SwooleCoroutine\run(function (): void {
            $dispatcher = m::mock(Dispatcher::class);
            $dispatcher->shouldReceive('dispatch')
                ->once()
                ->with(m::type(OnWorkerExit::class));
            $coordinator = CoordinatorManager::until(Constants::WORKER_EXIT);

            (new WorkerExitCallback($dispatcher))->onWorkerExit(
                m::mock(Server::class),
                3,
            );

            $this->assertTrue($coordinator->isClosing());
            $this->assertSame(1, SwooleCoroutine::stats()['coroutine_num']);
        });
    }

    #[RunInSeparateProcess]
    public function testWorkerExitResumesCoordinationWhenAListenerThrows(): void
    {
        SwooleCoroutine\run(function (): void {
            $failure = new RuntimeException('worker exit listener failed');
            $dispatcher = m::mock(Dispatcher::class);
            $dispatcher->shouldReceive('dispatch')->once()->andThrow($failure);
            $coordinator = CoordinatorManager::until(Constants::WORKER_EXIT);

            try {
                (new WorkerExitCallback($dispatcher))->onWorkerExit(
                    m::mock(Server::class),
                    3,
                );
                $this->fail('The listener failure should propagate.');
            } catch (RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }

            $this->assertTrue($coordinator->isClosing());
        });
    }
}
