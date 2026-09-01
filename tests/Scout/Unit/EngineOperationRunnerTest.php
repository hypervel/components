<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\EngineOperationObserver;
use Hypervel\Scout\EngineOperation;
use Hypervel\Scout\EngineOperationRunner;
use Hypervel\Tests\TestCase;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class EngineOperationRunnerTest extends TestCase
{
    public function testRunsCallbackDirectlyWithoutObservers(): void
    {
        $runner = new EngineOperationRunner;
        $calls = 0;

        $result = $runner->run($this->operation(), function () use (&$calls): string {
            ++$calls;

            return 'result';
        });

        $this->assertFalse($runner->hasObservers());
        $this->assertSame(1, $calls);
        $this->assertSame('result', $result);
    }

    public function testNotifiesObserversInRegistrationOrderWithTheirTokens(): void
    {
        $runner = new EngineOperationRunner;
        $operation = $this->operation();
        $events = [];

        $runner->observe($this->observer(
            function (EngineOperation $givenOperation) use (&$events): string {
                $events[] = ['start-first', $givenOperation];

                return 'first-token';
            },
            function (EngineOperation $givenOperation, mixed $token, ?Throwable $exception) use (&$events): void {
                $events[] = ['finish-first', $givenOperation, $token, $exception];
            },
        ));
        $runner->observe($this->observer(
            function (EngineOperation $givenOperation) use (&$events): string {
                $events[] = ['start-second', $givenOperation];

                return 'second-token';
            },
            function (EngineOperation $givenOperation, mixed $token, ?Throwable $exception) use (&$events): void {
                $events[] = ['finish-second', $givenOperation, $token, $exception];
            },
        ));

        $result = $runner->run($operation, function () use (&$events): string {
            $events[] = ['operation'];

            return 'result';
        });

        $this->assertTrue($runner->hasObservers());
        $this->assertSame('result', $result);
        $this->assertSame([
            ['start-first', $operation],
            ['start-second', $operation],
            ['operation'],
            ['finish-first', $operation, 'first-token', null],
            ['finish-second', $operation, 'second-token', null],
        ], $events);
    }

    public function testStartingFailureFinishesPreviouslyStartedObserversAndRemainsPrimary(): void
    {
        $runner = new EngineOperationRunner;
        $startingFailure = new RuntimeException('starting failed');
        $completionFailure = new RuntimeException('completion failed');
        $receivedException = null;
        $callbackCalled = false;

        $runner->observe($this->observer(
            fn (): string => 'first-token',
            function (EngineOperation $operation, mixed $token, ?Throwable $exception) use (
                &$receivedException,
                $completionFailure
            ): void {
                $receivedException = $exception;

                throw $completionFailure;
            },
        ));
        $runner->observe($this->observer(
            function () use ($startingFailure): never {
                throw $startingFailure;
            },
            fn (): null => null,
        ));

        $caught = null;

        try {
            $runner->run($this->operation(), function () use (&$callbackCalled): void {
                $callbackCalled = true;
            });
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($startingFailure, $caught);
        $this->assertSame($startingFailure, $receivedException);
        $this->assertFalse($callbackCalled);
    }

    public function testOperationFailureFinishesEveryObserverAndRemainsPrimary(): void
    {
        $runner = new EngineOperationRunner;
        $operationFailure = new RuntimeException('operation failed');
        $completionFailure = new RuntimeException('completion failed');
        $events = [];

        $runner->observe($this->observer(
            fn (): string => 'first-token',
            function (EngineOperation $operation, mixed $token, ?Throwable $exception) use (
                &$events,
                $completionFailure
            ): void {
                $events[] = [$token, $exception];

                throw $completionFailure;
            },
        ));
        $runner->observe($this->observer(
            fn (): string => 'second-token',
            function (EngineOperation $operation, mixed $token, ?Throwable $exception) use (&$events): void {
                $events[] = [$token, $exception];
            },
        ));

        $caught = null;

        try {
            $runner->run($this->operation(), function () use ($operationFailure): never {
                throw $operationFailure;
            });
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($operationFailure, $caught);
        $this->assertSame([
            ['first-token', $operationFailure],
            ['second-token', $operationFailure],
        ], $events);
    }

    public function testSuccessfulOperationAttemptsEveryCompletionAndThrowsFirstFailure(): void
    {
        $runner = new EngineOperationRunner;
        $firstFailure = new RuntimeException('first completion failed');
        $secondFailure = new RuntimeException('second completion failed');
        $finished = [];

        $runner->observe($this->observer(
            fn (): string => 'first-token',
            function () use (&$finished, $firstFailure): never {
                $finished[] = 'first';

                throw $firstFailure;
            },
        ));
        $runner->observe($this->observer(
            fn (): string => 'second-token',
            function () use (&$finished, $secondFailure): never {
                $finished[] = 'second';

                throw $secondFailure;
            },
        ));

        $caught = null;

        try {
            $runner->run($this->operation(), fn (): string => 'result');
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($firstFailure, $caught);
        $this->assertSame(['first', 'second'], $finished);
    }

    public function testStartingCancellationSkipsAllCompletionAndOperationWork(): void
    {
        $runner = new EngineOperationRunner;
        $cancellation = new CanceledException;
        $finished = false;
        $callbackCalled = false;

        $runner->observe($this->observer(
            fn (): string => 'first-token',
            function () use (&$finished): void {
                $finished = true;
            },
        ));
        $runner->observe($this->observer(
            function () use ($cancellation): never {
                throw $cancellation;
            },
            fn (): null => null,
        ));

        $caught = null;

        try {
            $runner->run($this->operation(), function () use (&$callbackCalled): void {
                $callbackCalled = true;
            });
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($cancellation, $caught);
        $this->assertFalse($finished);
        $this->assertFalse($callbackCalled);
    }

    public function testOperationCancellationSkipsAllCompletion(): void
    {
        $runner = new EngineOperationRunner;
        $cancellation = new CanceledException;
        $finished = false;

        $runner->observe($this->observer(
            fn (): string => 'token',
            function () use (&$finished): void {
                $finished = true;
            },
        ));

        $caught = null;

        try {
            $runner->run($this->operation(), function () use ($cancellation): never {
                throw $cancellation;
            });
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($cancellation, $caught);
        $this->assertFalse($finished);
    }

    public function testCompletionCancellationStopsLaterObserversAndSupersedesOperationFailure(): void
    {
        $runner = new EngineOperationRunner;
        $operationFailure = new RuntimeException('operation failed');
        $cancellation = new CanceledException;
        $secondFinished = false;

        $runner->observe($this->observer(
            fn (): string => 'first-token',
            function () use ($cancellation): never {
                throw $cancellation;
            },
        ));
        $runner->observe($this->observer(
            fn (): string => 'second-token',
            function () use (&$secondFinished): void {
                $secondFinished = true;
            },
        ));

        $caught = null;

        try {
            $runner->run($this->operation(), function () use ($operationFailure): never {
                throw $operationFailure;
            });
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($cancellation, $caught);
        $this->assertFalse($secondFinished);
    }

    protected function operation(): EngineOperation
    {
        return new EngineOperation(
            'search',
            'null',
            Model::class,
            'models',
        );
    }

    protected function observer(Closure $starting, Closure $finished): EngineOperationObserver
    {
        return new ScoutEngineOperationObserverStub($starting, $finished);
    }
}

class ScoutEngineOperationObserverStub implements EngineOperationObserver
{
    public function __construct(
        protected Closure $startingCallback,
        protected Closure $finishedCallback,
    ) {
    }

    public function starting(EngineOperation $operation): mixed
    {
        return ($this->startingCallback)($operation);
    }

    public function finished(
        EngineOperation $operation,
        mixed $token,
        ?Throwable $exception
    ): void {
        ($this->finishedCallback)($operation, $token, $exception);
    }
}
