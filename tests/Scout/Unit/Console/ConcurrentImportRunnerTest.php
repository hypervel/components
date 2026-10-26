<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\DatabaseManager;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Scout\Console\ConcurrentImportRunner;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class ConcurrentImportRunnerTest extends TestCase
{
    public function testChildrenPreserveTheScopedDefaultDatabaseConnection(): void
    {
        $container = new Container;
        $container->instance('config', new Repository(['database' => ['default' => 'central']]));
        $database = new DatabaseManager($container, new ConnectionFactory($container));
        $runner = new ConcurrentImportRunner(1);
        $connection = null;

        $database->usingConnection('tenant', static function () use ($database, $runner, &$connection): void {
            $runner->create(static function () use ($database, &$connection): void {
                $connection = $database->getDefaultConnection();
            });
            $runner->wait();
        });

        $this->assertSame('tenant', $connection);
    }

    public function testZeroCapacityRunsInlineAndPreservesParentCancellation(): void
    {
        $runner = new ConcurrentImportRunner(0);
        $parentId = Coroutine::id();
        $operationId = null;
        $runner->create(static function () use (&$operationId): void {
            $operationId = Coroutine::id();
        });
        $runner->wait();

        $this->assertSame($parentId, $operationId);

        $started = new Channel(1);
        $blocker = new Channel(1);
        $finished = new Channel(1);
        $nativeCancellation = null;
        $caught = null;
        $parent = EngineCoroutine::create(static function () use ($started, $blocker, $finished, &$nativeCancellation, &$caught): void {
            try {
                (new ConcurrentImportRunner(0))->create(static function () use ($started, $blocker, &$nativeCancellation): void {
                    $started->push(true);

                    try {
                        $blocker->pop();
                    } catch (CanceledException $exception) {
                        $nativeCancellation = $exception;
                        throw $exception;
                    }
                });
            } catch (CanceledException $exception) {
                $caught = $exception;
            } finally {
                $finished->push(true);
            }
        });

        try {
            $this->assertTrue($started->pop(1));
            $this->assertTrue(EngineCoroutine::cancelById($parent->getId(), throwException: true));
            $this->assertTrue($finished->pop(1));
        } finally {
            $blocker->push(true);
        }

        $this->assertInstanceOf(CanceledException::class, $nativeCancellation);
        $this->assertSame($nativeCancellation, $caught);
    }

    public function testWaitDrainsActiveChildrenBeforeRethrowingTheFirstFailure(): void
    {
        $runner = new ConcurrentImportRunner(2);
        $completed = false;
        $failure = new RuntimeException('Import failed.');

        $runner->create(function () use (&$completed): void {
            usleep(10_000);
            $completed = true;
        });

        try {
            $runner->create(function () use ($failure): void {
                throw $failure;
            });
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        try {
            $runner->wait();
            $this->fail('The child import failure was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertTrue($completed);
    }

    public function testWaitPreservesTheFirstFailureWhenMultipleChildrenFail(): void
    {
        $runner = new ConcurrentImportRunner(2);
        $releaseFirst = new Channel(1);
        $releaseSecond = new Channel(1);
        $firstFailure = new RuntimeException('First failure.');
        $secondFailure = new RuntimeException('Second failure.');

        $runner->create(function () use ($releaseFirst, $firstFailure): void {
            $releaseFirst->pop();
            throw $firstFailure;
        });

        $runner->create(function () use ($releaseSecond, $secondFailure): void {
            $releaseSecond->pop();
            throw $secondFailure;
        });

        $releaseFirst->push(true);
        usleep(10_000);
        $releaseSecond->push(true);

        try {
            $runner->wait();
            $this->fail('The child import failure was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame($firstFailure, $exception);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testWaitSurfacesIndependentChildCancellationAsAChildFailure(bool $cleanupThrows): void
    {
        $runner = new ConcurrentImportRunner(1);
        $operationStarted = new Channel(1);
        $blocker = new Channel(1);
        $childCoroutineId = null;
        $nativeCancellation = null;
        $cleanupException = new RuntimeException('Import cleanup failed.');

        $runner->create(static function () use (
            $operationStarted,
            $blocker,
            $cleanupThrows,
            $cleanupException,
            &$childCoroutineId,
            &$nativeCancellation,
        ): void {
            $childCoroutineId = Coroutine::id();
            $operationStarted->push(true);

            try {
                $blocker->pop();
            } catch (CanceledException $exception) {
                $nativeCancellation = $exception;
                throw $exception;
            } finally {
                if ($cleanupThrows) {
                    Coroutine::sleep(0.001);
                    throw $cleanupException;
                }
            }
        });

        try {
            $this->assertTrue($operationStarted->pop(1));
            $this->assertIsInt($childCoroutineId);
            $this->assertTrue(EngineCoroutine::cancelById($childCoroutineId, throwException: true));
            $runner->wait();
            $this->fail('The child cancellation was not rethrown.');
        } catch (ChildCancellationException $exception) {
            $this->assertSame('A child coroutine running a Scout import was canceled while its owner remained active.', $exception->getMessage());
            $this->assertSame($cleanupThrows ? $cleanupException : $nativeCancellation, $exception->getPrevious());
            $this->assertFalse(Coroutine::isCancellation($exception));

            if ($cleanupThrows) {
                $this->assertSame($nativeCancellation, $cleanupException->getPrevious());
            }
        } finally {
            $blocker->close();
            if ($childCoroutineId !== null) {
                Coroutine::join([$childCoroutineId], 1);
            }
        }
    }
}
