<?php

declare(strict_types=1);

namespace Hypervel\Tests\Prompts;

use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Prompts\Support\PromptAnimation;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\CanceledException;

class PromptAnimationTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    #[RunInSeparateProcess]
    public function testOwnerCancellationDuringCreationLeavesAnimationStoppable(): void
    {
        SwooleCoroutine\run(function (): void {
            $hookFailure = new RuntimeException('The startup hook failed.');
            $reportStarted = new Channel(1);
            $releaseReport = new Channel(1);
            $parentCoroutineId = EngineCoroutine::id();
            $childCoroutineId = null;
            $exceptionHandler = m::mock(ExceptionHandlerContract::class);
            $exceptionHandler->shouldReceive('report')
                ->once()
                ->with($hookFailure)
                ->andReturnUsing(static function () use ($reportStarted, $releaseReport, &$childCoroutineId): void {
                    $childCoroutineId = EngineCoroutine::id();
                    $reportStarted->push(true);
                    $releaseReport->pop();
                });
            Container::getInstance()->instance(ExceptionHandlerContract::class, $exceptionHandler);

            EngineCoroutine::create(static function () use ($reportStarted, $releaseReport, $parentCoroutineId): void {
                $reportStarted->pop();
                EngineCoroutine::cancelById($parentCoroutineId, throwException: true);
                $releaseReport->push(true);
            });

            Coroutine::afterCreated(static function () use ($hookFailure): never {
                throw $hookFailure;
            });

            $rendered = false;
            $animation = new PromptAnimation(
                render: static function () use (&$rendered): void {
                    $rendered = true;
                },
                interval: 60_000,
            );

            try {
                $animation->start();
                $this->fail('Expected owner cancellation to escape animation creation.');
            } catch (CanceledException) {
            } finally {
                $animation->stop();
            }

            $this->assertFalse($rendered);
            $this->assertIsInt($childCoroutineId);
            $this->assertFalse(Coroutine::exists($childCoroutineId));
            $this->assertSame(1, SwooleCoroutine::stats()['coroutine_num']);
        });
    }
}
