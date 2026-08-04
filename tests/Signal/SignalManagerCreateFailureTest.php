<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Signal\SignalHandler;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Signal\SignalManager;
use Hypervel\Support\SafeCaller;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Swoole\Coroutine as SwooleCoroutine;

class SignalManagerCreateFailureTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testPartialListenerCreationCancelsEarlierWatchers(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 2]);

        SwooleCoroutine\run(function (): void {
            $exceptionHandler = m::mock(ExceptionHandlerContract::class);
            $exceptionHandler->shouldNotReceive('report');
            Container::getInstance()->instance(
                ExceptionHandlerContract::class,
                $exceptionHandler,
            );

            $handler = new class implements SignalHandler {
                public function signals(): array
                {
                    return [self::WORKER => [SIGUSR1, SIGUSR2]];
                }

                public function handle(int $signal): void
                {
                }
            };
            $container = m::mock(ContainerContract::class);
            $container->shouldReceive('make')->with(ConfigContract::class)->andReturn(new Repository([
                'signal' => ['handlers' => [$handler::class]],
            ]));
            $container->shouldReceive('make')->with(SafeCaller::class)->andReturn(new SafeCaller($container));
            $container->shouldReceive('make')->with($handler::class)->andReturn($handler);
            $manager = new SignalManager($container);

            try {
                $manager->listen(SignalHandler::WORKER);
                $this->fail('Expected the second signal watcher creation to fail.');
            } catch (CoroutineCreateException) {
                $this->assertSame(1, SwooleCoroutine::stats()['coroutine_num']);
            } finally {
                $manager->stop();
            }
        });
    }
}
