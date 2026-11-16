<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Bootstrap;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Core\Bootstrap\WorkerExitCallback;
use Hypervel\Core\Events\OnWorkerExit;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Server;

class WorkerExitCallbackTest extends TestCase
{
    use VerifiesDoubles;

    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testWorkerExitDoesNotRequireAnotherCoroutineSlot(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);

        SwooleCoroutine\run(function (): void {
            $dispatcher = Double::for(Dispatcher::class);
            $dispatcher->expects('hasListeners')
                ->with(OnWorkerExit::class)
                ->returns(true);
            $dispatcher->expects('dispatch')
                ->with(Argument::type(OnWorkerExit::class));
            $coordinator = CoordinatorManager::until(Constants::WORKER_EXIT);
            $callback = new WorkerExitCallback($dispatcher);
            $server = Double::for(Server::class);

            $callback->onWorkerExit($server, 3);
            $callback->onWorkerExit($server, 3);

            $this->assertTrue($coordinator->isClosing());
            $this->assertSame(1, SwooleCoroutine::stats()['coroutine_num']);
        });
    }

    #[RunInSeparateProcess]
    public function testWorkerExitResumesCoordinationWithoutListeners(): void
    {
        SwooleCoroutine\run(function (): void {
            $dispatcher = Double::for(Dispatcher::class);
            $dispatcher->expects('hasListeners')
                ->with(OnWorkerExit::class)
                ->returns(false);
            $dispatcher->expects('dispatch')->never();
            $coordinator = CoordinatorManager::until(Constants::WORKER_EXIT);
            $callback = new WorkerExitCallback($dispatcher);

            $callback->onWorkerExit(Double::for(Server::class), 3);
            $callback->onWorkerExit(Double::for(Server::class), 3);

            $this->assertTrue($coordinator->isClosing());
        });
    }

    #[RunInSeparateProcess]
    public function testWorkerExitResumesCoordinationWhenAListenerThrows(): void
    {
        SwooleCoroutine\run(function (): void {
            $failure = new RuntimeException('worker exit listener failed');
            $dispatcher = Double::for(Dispatcher::class);
            $dispatcher->expects('hasListeners')
                ->with(OnWorkerExit::class)
                ->returns(true);
            $dispatcher->expects('dispatch')->throws($failure);
            $coordinator = CoordinatorManager::until(Constants::WORKER_EXIT);
            $callback = new WorkerExitCallback($dispatcher);
            $server = Double::for(Server::class);

            try {
                $callback->onWorkerExit($server, 3);
                $this->fail('The listener failure should propagate.');
            } catch (RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }

            $callback->onWorkerExit($server, 3);

            $this->assertTrue($coordinator->isClosing());
        });
    }
}
