<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Signal\SignalHandlerInterface as SignalHandler;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Signal\SignalManager;
use Hypervel\Tests\Signal\Fixtures\SignalHandlerStub;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionClass;
use ReflectionProperty;
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

            $manager = (new ReflectionClass(SignalManager::class))
                ->newInstanceWithoutConstructor();
            $handler = new SignalHandlerStub;

            (new ReflectionProperty($manager, 'handlers'))->setValue($manager, [
                SignalHandler::WORKER => [
                    SIGUSR1 => [$handler],
                    SIGUSR2 => [$handler],
                ],
            ]);

            try {
                $manager->listen(SignalHandler::WORKER);
                $this->fail('Expected the second signal watcher creation to fail.');
            } catch (CoroutineCreateException) {
                $this->assertSame(1, SwooleCoroutine::stats()['coroutine_num']);
            }
        });
    }
}
