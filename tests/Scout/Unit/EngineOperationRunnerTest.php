<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\EngineOperationObserver;
use Hypervel\Scout\EngineOperation;
use Hypervel\Scout\EngineOperationRunner;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class EngineOperationRunnerTest extends TestCase
{
    use VerifiesDoubles;

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

        $this->bindReportingHandler()->expects('report')->with(Argument::same($completionFailure));
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

    #[TestWith([false])]
    #[TestWith([true], 'second observer rethrows the operation failure')]
    public function testOperationFailureFinishesEveryObserverAndRemainsPrimary(bool $rethrow): void
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
            function (EngineOperation $operation, mixed $token, ?Throwable $exception) use (&$events, $rethrow): void {
                $events[] = [$token, $exception];

                if ($rethrow && $exception !== null) {
                    throw $exception;
                }
            },
        ));

        $reported = [];
        $this->bindReportingHandler()->allows('report')->resolves(function (Throwable $throwable) use (&$reported): void {
            $reported[] = $throwable;
        });
        $caught = null;

        try {
            $runner->run($this->operation(), function () use ($operationFailure): never {
                throw $operationFailure;
            });
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($operationFailure, $caught);
        $this->assertSame([$completionFailure], $reported);
        $this->assertSame([
            ['first-token', $operationFailure],
            ['second-token', $operationFailure],
        ], $events);
    }

    public function testSuccessfulOperationAttemptsEveryCompletionThrowsTheFirstFailureAndReportsTheRest(): void
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

        $this->bindReportingHandler()->expects('report')->with(Argument::same($secondFailure));
        $caught = null;

        try {
            $runner->run($this->operation(), fn (): string => 'result');
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($firstFailure, $caught);
        $this->assertSame(['first', 'second'], $finished);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testStartingCancellationFinishesPreviouslyStartedObserversWithoutRunningTheOperation(bool $cleanupThrows): void
    {
        $runner = new EngineOperationRunner;
        $cancellation = $cleanupThrows ? new RuntimeException('Cleanup failed.', previous: new CanceledException) : new CanceledException;
        $receivedException = null;
        $callbackCalled = false;

        $runner->observe($this->observer(
            fn (): string => 'first-token',
            function (EngineOperation $operation, mixed $token, ?Throwable $exception) use (&$receivedException): void {
                $receivedException = $exception;
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
        $this->assertSame($cancellation, $receivedException);
        $this->assertFalse($callbackCalled);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testOperationCancellationIsPassedToEveryObserver(bool $cleanupThrows): void
    {
        $runner = new EngineOperationRunner;
        $cancellation = $cleanupThrows ? new RuntimeException('Cleanup failed.', previous: new CanceledException) : new CanceledException;
        $received = [];

        foreach (['first', 'second'] as $name) {
            $runner->observe($this->observer(
                fn (): string => $name,
                function (EngineOperation $operation, mixed $token, ?Throwable $exception) use (&$received): void {
                    $received[$token] = $exception;
                },
            ));
        }

        $caught = null;

        try {
            $runner->run($this->operation(), function () use ($cancellation): never {
                throw $cancellation;
            });
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($cancellation, $caught);
        $this->assertSame(['first' => $cancellation, 'second' => $cancellation], $received);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCompletionCancellationNotifiesLaterObserversAndSupersedesTheReportedOperationFailure(bool $cleanupThrows): void
    {
        $runner = new EngineOperationRunner;
        $operationFailure = new RuntimeException('operation failed');
        $cancellation = $cleanupThrows ? new RuntimeException('Cleanup failed.', previous: new CanceledException) : new CanceledException;
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
        $this->bindReportingHandler()->expects('report')->with(Argument::same($operationFailure));

        $caught = null;

        try {
            $runner->run($this->operation(), function () use ($operationFailure): never {
                throw $operationFailure;
            });
        } catch (Throwable $throwable) {
            $caught = $throwable;
        }

        $this->assertSame($cancellation, $caught);
        $this->assertTrue($secondFinished);
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

    /**
     * Bind an exception handler double for displaced failure reports.
     */
    private function bindReportingHandler(): ExceptionHandler&DoubleInterface
    {
        $container = new Container;
        Container::setInstance($container);
        $handler = Double::for(ExceptionHandler::class)->strict();
        $container->instance(ExceptionHandler::class, $handler);

        return $handler;
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
