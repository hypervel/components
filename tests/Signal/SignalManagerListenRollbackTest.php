<?php

declare(strict_types=1);

namespace Hypervel\Tests\Signal;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Signal\SignalHandler;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\SignalRegistry;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Signal\SignalManager;
use Hypervel\Support\SafeCaller;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\CanceledException;

class SignalManagerListenRollbackTest extends TestCase
{
    use VerifiesDoubles;

    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testPartialListenerCreationCancelsEarlierWatchers(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 2]);

        SwooleCoroutine\run(function (): void {
            $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
            $exceptionHandler->expects('report')->never();
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
            $container = new Container;
            $container->instance(ConfigContract::class, new Repository([
                'signal' => ['handlers' => [$handler::class]],
            ]));
            $container->instance(SafeCaller::class, new SafeCaller($container));
            $container->instance(SignalRegistry::class, new SignalRegistry);
            $container->instance($handler::class, $handler);
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

    #[RunInSeparateProcess]
    public function testOwnerCancellationAfterWatcherStartRollsBackTheWatcher(): void
    {
        SwooleCoroutine\run(function (): void {
            $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
            Container::getInstance()->instance(
                ExceptionHandlerContract::class,
                $exceptionHandler,
            );

            $hookFailure = new RuntimeException('The startup hook failed.');
            $reportStarted = new Channel(1);
            $releaseReport = new Channel(1);
            $parentCoroutineId = EngineCoroutine::id();
            $parentCancellation = null;
            $childCoroutineId = null;

            $exceptionHandler->expects('report')
                ->with(Argument::same($hookFailure))
                ->resolves(static function () use ($reportStarted, $releaseReport, &$childCoroutineId): void {
                    $childCoroutineId = EngineCoroutine::id();
                    $reportStarted->push(true);
                    $releaseReport->pop();
                });

            EngineCoroutine::create(static function () use ($reportStarted, $parentCoroutineId): void {
                $reportStarted->pop();
                EngineCoroutine::cancelById($parentCoroutineId, throwException: true);
            });

            Coroutine::afterCreated(static function () use ($hookFailure): never {
                throw $hookFailure;
            });

            $handler = new class implements SignalHandler {
                public function signals(): array
                {
                    return [self::WORKER => [SIGUSR1]];
                }

                public function handle(int $signal): void
                {
                }
            };
            $container = new Container;
            $container->instance(ConfigContract::class, new Repository([
                'signal' => ['handlers' => [$handler::class]],
            ]));
            $container->instance(SafeCaller::class, new SafeCaller($container));
            $container->instance(SignalRegistry::class, new SignalRegistry);
            $container->instance($handler::class, $handler);
            $manager = new SignalManager($container);

            try {
                $manager->listen(SignalHandler::WORKER);
                $this->fail('Expected owner cancellation to escape listener creation.');
            } catch (CanceledException $exception) {
                $parentCancellation = $exception;
            } finally {
                $releaseReport->push(true, 0.001);
                $manager->stop();
            }

            $this->assertInstanceOf(CanceledException::class, $parentCancellation);
            $this->assertIsInt($childCoroutineId);
            $this->assertFalse(Coroutine::exists($childCoroutineId));
        });
    }
}
