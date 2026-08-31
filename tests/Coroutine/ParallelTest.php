<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Exception;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\NonCopyableContext;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ChannelClosedException;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Coroutine\Exceptions\ParallelExecutionException;
use Hypervel\Coroutine\Parallel;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Tests\Context\Fixtures\ThrowingReplicableContext;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\parallel;

class ParallelTest extends TestCase
{
    public function testParallel()
    {
        // Closure
        $parallel = new Parallel;
        for ($i = 0; $i < 3; ++$i) {
            $parallel->add(function () {
                return Coroutine::id();
            });
        }
        $result = $parallel->wait();
        $id = $result[0];
        $this->assertSame([$id, $id + 1, $id + 2], $result);

        // Array
        $parallel = new Parallel;
        for ($i = 0; $i < 3; ++$i) {
            $parallel->add([$this, 'returnCoroutineId']);
        }
        $result = $parallel->wait();
        $id = $result[0];
        $this->assertSame([$id, $id + 1, $id + 2], $result);
    }

    public function testParallelAcceptsAnEmptyCallbackList(): void
    {
        $this->assertSame([], parallel([]));
    }

    public function testParallelConcurrent()
    {
        $parallel = new Parallel;
        $num = 0;
        $callback = function () use (&$num) {
            ++$num;
            Coroutine::sleep(0.01);
            return $num;
        };
        for ($i = 0; $i < 4; ++$i) {
            $parallel->add($callback);
        }
        $res = $parallel->wait();
        $this->assertSame([4, 4, 4, 4], array_values($res));

        $parallel = new Parallel(2);
        $num = 0;
        $callback = function () use (&$num) {
            ++$num;
            Coroutine::sleep(0.01);
            return $num;
        };
        for ($i = 0; $i < 4; ++$i) {
            $parallel->add($callback);
        }
        $res = $parallel->wait();
        sort($res);
        $this->assertSame([2, 3, 4, 4], array_values($res));

        $num = 10;
        $callbacks = [];
        for ($i = 0; $i < 4; ++$i) {
            $callbacks[] = function () use (&$num) {
                ++$num;
                Coroutine::sleep(0.01);
                return $num;
            };
        }
        $res = parallel($callbacks, 2);
        sort($res);
        $this->assertSame([12, 13, 14, 14], array_values($res));
    }

    public function testParallelReturnsAfterChildCoroutinesExit(): void
    {
        $childCoroutineId = null;
        $cleanupCompleted = false;
        $parallel = new Parallel;
        $parallel->add(function () use (&$childCoroutineId, &$cleanupCompleted): string {
            $childCoroutineId = Coroutine::id();
            Coroutine::defer(function () use (&$cleanupCompleted): void {
                Coroutine::sleep(0.001);
                $cleanupCompleted = true;
            });

            return 'result';
        });

        $this->assertSame(['result'], $parallel->wait());
        $this->assertTrue($cleanupCompleted);
        $this->assertIsInt($childCoroutineId);
        $this->assertFalse(Coroutine::exists($childCoroutineId));
    }

    public function testParallelCallbackCount()
    {
        $parallel = new Parallel;
        $callback = function () {
            return 1;
        };
        for ($i = 0; $i < 4; ++$i) {
            $parallel->add($callback);
        }
        $res = $parallel->wait();
        $this->assertEquals(count($res), 4);

        for ($i = 0; $i < 4; ++$i) {
            $parallel->add($callback);
        }
        $res = $parallel->wait();
        $this->assertEquals(count($res), 8);
    }

    public function testParallelClear()
    {
        $parallel = new Parallel;
        $callback = function () {
            return 1;
        };
        for ($i = 0; $i < 4; ++$i) {
            $parallel->add($callback);
        }
        $res = $parallel->wait();
        $parallel->clear();
        $this->assertEquals(count($res), 4);

        for ($i = 0; $i < 4; ++$i) {
            $parallel->add($callback);
        }
        $res = $parallel->wait();
        $parallel->clear();
        $this->assertEquals(count($res), 4);
    }

    public function testParallelKeys()
    {
        $parallel = new Parallel;
        $callback = function () {
            return 1;
        };
        for ($i = 0; $i < 4; ++$i) {
            $parallel->add($callback);
        }
        $res = $parallel->wait();
        $parallel->clear();
        $this->assertSame([1, 1, 1, 1], $res);

        for ($i = 0; $i < 4; ++$i) {
            $parallel->add($callback, 'id_' . $i);
        }
        $res = $parallel->wait();
        $parallel->clear();
        $this->assertSame(['id_0' => 1, 'id_1' => 1, 'id_2' => 1, 'id_3' => 1], $res);

        for ($i = 0; $i < 4; ++$i) {
            $parallel->add($callback, $i - 1);
        }
        $res = $parallel->wait();
        $parallel->clear();
        $this->assertSame([-1 => 1, 0 => 1, 1 => 1, 2 => 1], $res);

        $parallel->add($callback, 1);
        $res = $parallel->wait();
        $parallel->clear();
        $this->assertSame([1 => 1], $res);
    }

    public function testParallelThrows()
    {
        $parallel = new Parallel;
        $err = function () {
            Coroutine::sleep(0.001);
            throw new RuntimeException('something bad happened');
        };
        $ok = function () {
            Coroutine::sleep(0.001);
            return 1;
        };
        $parallel->add($err);
        for ($i = 0; $i < 4; ++$i) {
            $parallel->add($ok);
        }
        $this->expectException(ParallelExecutionException::class);
        $res = $parallel->wait();
    }

    public function testParallelThrowsAfterChildCoroutinesExit(): void
    {
        $childCoroutineId = null;
        $cleanupCompleted = false;
        $parallel = new Parallel;
        $parallel->add(function () use (&$childCoroutineId, &$cleanupCompleted): void {
            $childCoroutineId = Coroutine::id();
            Coroutine::defer(function () use (&$cleanupCompleted): void {
                Coroutine::sleep(0.001);
                $cleanupCompleted = true;
            });

            throw new RuntimeException('something bad happened');
        });

        try {
            $parallel->wait();
            $this->fail('The parallel executor should throw after its child exits.');
        } catch (ParallelExecutionException) {
        }

        $this->assertTrue($cleanupCompleted);
        $this->assertIsInt($childCoroutineId);
        $this->assertFalse(Coroutine::exists($childCoroutineId));
    }

    public function testIndependentChildCancellationIsStoredAsAChildFailure(): void
    {
        $parallel = new Parallel;
        $childStarted = new Channel(1);
        $blocker = new Channel(1);
        $childCoroutineId = null;
        $childCancellation = null;
        $outcome = null;

        $parallel->add(static function () use ($childStarted, $blocker, &$childCoroutineId, &$childCancellation): void {
            $childCoroutineId = Coroutine::id();
            $childStarted->push(true);

            try {
                $blocker->pop();
            } catch (CanceledException $exception) {
                $childCancellation = $exception;
                throw $exception;
            }
        }, 'canceled');

        $runner = EngineCoroutine::create(function () use ($parallel, &$outcome): void {
            $parallel->wait(false);
            $outcome = $parallel->getThrowables()['canceled'];
        });

        $this->assertTrue($childStarted->pop());
        $this->assertIsInt($childCoroutineId);
        $this->assertTrue(EngineCoroutine::cancelById($childCoroutineId, throwException: true));
        Coroutine::join([$runner->getId()]);

        $this->assertInstanceOf(ChildCancellationException::class, $outcome);
        $this->assertSame('A child coroutine managed by Parallel was canceled while its owner remained active.', $outcome->getMessage());
        $this->assertSame($childCancellation, $outcome->getPrevious());
    }

    public function testOwnerCancellationCancelsEveryLiveChildAndEscapesExactly(): void
    {
        $parallel = new Parallel;
        $childrenStarted = new Channel(2);
        $blocker = new Channel(1);
        $childCoroutineIds = [];
        $childCancellations = [];
        $parentCancellation = null;

        foreach (['first', 'second'] as $key) {
            $parallel->add(static function () use (
                $key,
                $childrenStarted,
                $blocker,
                &$childCoroutineIds,
                &$childCancellations,
            ): void {
                $childCoroutineIds[$key] = Coroutine::id();
                $childrenStarted->push(true);

                try {
                    $blocker->pop();
                } catch (CanceledException $exception) {
                    $childCancellations[$key] = $exception;
                }
            }, $key);
        }

        $runner = EngineCoroutine::create(function () use ($parallel, &$parentCancellation): void {
            try {
                $parallel->wait();
            } catch (CanceledException $exception) {
                $parentCancellation = $exception;
            }
        });

        $this->assertTrue($childrenStarted->pop());
        $this->assertTrue($childrenStarted->pop());

        try {
            $this->assertTrue(EngineCoroutine::cancelById($runner->getId(), throwException: true));
            $this->assertInstanceOf(CanceledException::class, $parentCancellation);
            $this->assertCount(2, $childCancellations);

            foreach ($childCoroutineIds as $childCoroutineId) {
                $this->assertFalse(Coroutine::exists($childCoroutineId));
            }
        } finally {
            foreach ($childCoroutineIds as $childCoroutineId) {
                if (Coroutine::exists($childCoroutineId)) {
                    EngineCoroutine::cancelById($childCoroutineId, throwException: true);
                }
            }
        }
    }

    public function testNonThrowingCancellationWhileWaitingForCapacityDoesNotStartAnotherChild(): void
    {
        $parallel = new Parallel(1);
        $firstStarted = new Channel(1);
        $blocker = new Channel(1);
        $firstCancellation = null;
        $secondStarted = false;
        $parentCancellation = null;

        $parallel->add(static function () use ($firstStarted, $blocker, &$firstCancellation): void {
            $firstStarted->push(true);

            try {
                $blocker->pop();
            } catch (CanceledException $exception) {
                $firstCancellation = $exception;
            }
        }, 'first');
        $parallel->add(static function () use (&$secondStarted): void {
            $secondStarted = true;
        }, 'second');

        $runner = EngineCoroutine::create(function () use ($parallel, &$parentCancellation): void {
            try {
                $parallel->wait();
            } catch (CanceledException $exception) {
                $parentCancellation = $exception;
            }
        });

        $this->assertTrue($firstStarted->pop());

        try {
            $this->assertTrue(EngineCoroutine::cancelById($runner->getId()));
            $this->assertInstanceOf(CanceledException::class, $parentCancellation);
            $this->assertSame('Waiting to start parallel work was canceled.', $parentCancellation->getMessage());
            $this->assertInstanceOf(CanceledException::class, $firstCancellation);
            $this->assertFalse($secondStarted);
        } finally {
            if (Coroutine::exists($runner->getId())) {
                EngineCoroutine::cancelById($runner->getId(), throwException: true);
            }
        }
    }

    public function testCreationCancellationWhileStartupReportingYieldsReleasesOwnership(): void
    {
        $handler = m::mock(ExceptionHandlerContract::class);
        Container::getInstance()->instance(ExceptionHandlerContract::class, $handler);
        $parallel = new Parallel(1);
        $hookFailure = new RuntimeException('The startup hook failed.');
        $reportStarted = new Channel(1);
        $releaseReport = new Channel(1);
        $parentCoroutineId = null;
        $childCoroutineId = null;
        $parentCancellation = null;
        $childBodyRan = false;
        $failStartup = true;

        $handler->shouldReceive('report')
            ->once()
            ->with($hookFailure)
            ->andReturnUsing(static function () use ($reportStarted, $releaseReport, &$childCoroutineId): void {
                $childCoroutineId = EngineCoroutine::id();
                $reportStarted->push(true);
                $releaseReport->pop();
            });

        Coroutine::afterCreated(static function () use ($hookFailure, &$failStartup): void {
            if ($failStartup) {
                $failStartup = false;
                throw $hookFailure;
            }
        });

        $parallel->add(static function () use (&$childBodyRan): void {
            $childBodyRan = true;
        });

        $canceller = EngineCoroutine::create(static function () use ($reportStarted, &$parentCoroutineId): void {
            $reportStarted->pop();

            if (is_int($parentCoroutineId)) {
                EngineCoroutine::cancelById($parentCoroutineId, throwException: true);
            }
        });

        $parent = EngineCoroutine::create(function () use ($parallel, &$parentCoroutineId, &$parentCancellation): void {
            $parentCoroutineId = EngineCoroutine::id();

            try {
                $parallel->wait();
            } catch (CanceledException $exception) {
                $parentCancellation = $exception;
            }
        });

        try {
            $this->assertInstanceOf(CanceledException::class, $parentCancellation);
            $this->assertFalse($childBodyRan);
            $this->assertIsInt($childCoroutineId);
            $this->assertFalse(Coroutine::exists($childCoroutineId));
        } finally {
            $releaseReport->push(true, 0.001);

            if (is_int($childCoroutineId) && Coroutine::exists($childCoroutineId)) {
                EngineCoroutine::cancelById($childCoroutineId, throwException: true);
                Coroutine::join([$childCoroutineId], 1);
            }
        }

        $this->assertFalse(Coroutine::exists($parent->getId()));
        $this->assertFalse(Coroutine::exists($canceller->getId()));

        $parallel->clear();
        $parallel->add(static fn (): string => 'reused', 'next');

        $this->assertSame(['next' => 'reused'], $parallel->wait());
    }

    public function testClosedConcurrencyChannelIsStoredAsAnOrdinaryFailure(): void
    {
        $parallel = new ParallelTestParallel(1);
        $parallel->closeConcurrencyChannelForTest();
        $parallel->add(static fn (): string => 'never', 'closed');

        $this->assertSame([], $parallel->wait(false));
        $this->assertInstanceOf(ChannelClosedException::class, $parallel->getThrowables()['closed']);
        $this->assertSame('The parallel concurrency channel is closed.', $parallel->getThrowables()['closed']->getMessage());
    }

    public function testParallelResultsAndThrows()
    {
        $parallel = new Parallel;

        $err = function () {
            Coroutine::sleep(0.001);
            throw new RuntimeException('something bad happened');
        };
        $parallel->add($err);

        $ids = [1 => uniqid(), 2 => uniqid(), 3 => uniqid(), 4 => uniqid()];
        foreach ($ids as $id) {
            $parallel->add(function () use ($id) {
                Coroutine::sleep(0.001);
                return $id;
            });
        }

        try {
            $parallel->wait();
            throw new RuntimeException;
        } catch (ParallelExecutionException $exception) {
            foreach (['Detecting', 'RuntimeException', '#0'] as $keyword) {
                $this->assertTrue(str_contains($exception->getMessage(), $keyword));
            }

            $result = $exception->getResults();
            $this->assertEquals($ids, $result);

            $throwables = $exception->getThrowables();
            $this->assertTrue(count($throwables) === 1);
            $this->assertSame('something bad happened', $throwables[0]->getMessage());
        }
    }

    public function testParallelCount()
    {
        $parallel = new Parallel;
        $id = 0;
        $parallel->add(static function () use (&$id) {
            ++$id;
        });
        $parallel->add(static function () use (&$id) {
            ++$id;
        });
        $this->assertSame(2, $parallel->count());
        $parallel->wait();
        $this->assertSame(2, $parallel->count());
        $this->assertSame(2, $id);
        $parallel->wait();
        $this->assertSame(2, $parallel->count());
        $this->assertSame(4, $id);
    }

    public function testTheResultSort()
    {
        $res = parallel(['a' => function () {
            usleep(1000);
            return 1;
        }, 'b' => function () {
            return 2;
        }]);

        $this->assertSame(['a' => 1, 'b' => 2], $res);

        $res = parallel(['a' => function () {
            usleep(1000);
            return 1;
        }, 'b' => function () {
        }]);

        $this->assertSame(['a' => 1, 'b' => null], $res);
    }

    public function testThrowExceptionInParallel()
    {
        try {
            parallel([
                static function () {
                    throw new Exception;
                },
            ]);
        } catch (ParallelExecutionException $exception) {
            /** @var Throwable $exception */
            $exception = $exception->getThrowables()[0];
            $traces = $exception->getTrace();
            ob_start();
            var_dump($traces);
            $content = ob_get_clean();
            $this->assertStringNotContainsString('*RECURSION*', $content);
        }
    }

    public function testNewInspectionMethodsInitialState()
    {
        $parallel = new Parallel;

        $this->assertSame([], $parallel->getThrowables());
        $this->assertFalse($parallel->hasFailures());
        $this->assertSame(0, $parallel->failedCount());
    }

    public function testWaitWithoutThrowReturnsResultsAndCapturesThrowables()
    {
        $parallel = new Parallel;

        $err = new RuntimeException('boom');

        $parallel->add(function () use ($err) {
            Coroutine::sleep(0.001);
            throw $err;
        }, 'failed');

        $parallel->add(function () {
            Coroutine::sleep(0.001);
            return 'success-value';
        }, 'ok');

        $results = $parallel->wait(throw: false);

        $this->assertSame(['ok' => 'success-value'], $results);
        $this->assertArrayNotHasKey('failed', $results);
        $this->assertSame(['failed' => $err], $parallel->getThrowables());
    }

    public function testNewInspectionMethodsAfterAllSuccessRun()
    {
        $parallel = new Parallel;
        $parallel->add(function () {
            return 1;
        }, 'a');
        $parallel->add(function () {
            return 2;
        }, 'b');

        $parallel->wait(throw: false);

        $this->assertFalse($parallel->hasFailures());
        $this->assertSame(0, $parallel->failedCount());
        $this->assertSame([], $parallel->getThrowables());
    }

    public function testHasFailuresAndFailedCountReportFailures()
    {
        $parallel = new Parallel;

        $parallel->add(function () {
            Coroutine::sleep(0.001);
            throw new RuntimeException('first');
        }, 'a');

        $parallel->add(function () {
            Coroutine::sleep(0.001);
            return 1;
        }, 'b');

        $parallel->add(function () {
            Coroutine::sleep(0.001);
            throw new RuntimeException('second');
        }, 'c');

        $parallel->wait(throw: false);

        $this->assertTrue($parallel->hasFailures());
        $this->assertSame(2, $parallel->failedCount());
    }

    public function testStringAndNumericKeysArePreservedInThrowables()
    {
        $parallel = new Parallel;

        $parallel->add(function () {
            throw new RuntimeException('string-key');
        }, 'string-key');

        $parallel->add(function () {
            throw new RuntimeException('numeric-key');
        }, 42);

        $parallel->wait(throw: false);

        $throwables = $parallel->getThrowables();

        $this->assertArrayHasKey('string-key', $throwables);
        $this->assertArrayHasKey(42, $throwables);
        $this->assertSame('string-key', $throwables['string-key']->getMessage());
        $this->assertSame('numeric-key', $throwables[42]->getMessage());
    }

    public function testClearResetsNewMethodsState()
    {
        $parallel = new Parallel;

        $parallel->add(function () {
            throw new RuntimeException('boom');
        }, 'a');

        $parallel->wait(throw: false);

        $this->assertTrue($parallel->hasFailures());

        $parallel->clear();

        $this->assertSame([], $parallel->getThrowables());
        $this->assertFalse($parallel->hasFailures());
        $this->assertSame(0, $parallel->failedCount());
    }

    public function testWaitDoesNotLeakStateBetweenRuns()
    {
        $parallel = new Parallel;

        $parallel->add(function () {
            throw new RuntimeException('first-run-failure');
        }, 'shared-key');

        $parallel->wait(throw: false);
        $this->assertTrue($parallel->hasFailures());

        // Replace the failing callback with one that succeeds, using the same key.
        // Deliberately do NOT call clear() — this verifies that wait() resets its
        // own per-run state so previous failures cannot leak through.
        $parallel->add(function () {
            return 'now-succeeds';
        }, 'shared-key');

        $parallel->wait(throw: false);

        $this->assertFalse($parallel->hasFailures());
        $this->assertSame(0, $parallel->failedCount());
        $this->assertSame([], $parallel->getThrowables());
    }

    public function testConcurrencyLimitWithFailuresDoesNotDeadlock()
    {
        $parallel = new Parallel(2);

        for ($i = 0; $i < 4; ++$i) {
            $parallel->add(function () use ($i) {
                Coroutine::sleep(0.001);
                if ($i % 2 === 0) {
                    throw new RuntimeException("failure-{$i}");
                }
                return "success-{$i}";
            }, $i);
        }

        $results = $parallel->wait(throw: false);

        $this->assertCount(2, $results);
        $this->assertSame(2, $parallel->failedCount());
        $this->assertArrayHasKey(1, $results);
        $this->assertArrayHasKey(3, $results);
        $this->assertSame('success-1', $results[1]);
        $this->assertSame('success-3', $results[3]);

        // Run again to verify the concurrency channel was properly drained
        // and a second wait() does not hang.
        $parallel->clear();
        $parallel->add(function () {
            return 'second-run';
        }, 'second');

        $results = $parallel->wait(throw: false);

        $this->assertSame(['second' => 'second-run'], $results);
        $this->assertFalse($parallel->hasFailures());
    }

    public function testCopyContextDisabledByDefault()
    {
        CoroutineContext::set('parent_only', 'value');

        $channel = new Channel(1);
        $parallel = new Parallel;
        $parallel->add(function () use ($channel) {
            $channel->push(CoroutineContext::get('parent_only'));
        });
        $parallel->wait();

        $this->assertNull($channel->pop());
    }

    public function testCopyContextTrueCopiesAllKeys()
    {
        CoroutineContext::set('key_a', 'value_a');
        CoroutineContext::set('key_b', 'value_b');

        $channel = new Channel(2);
        $parallel = new Parallel(0, copyContext: true);
        $parallel->add(function () use ($channel) {
            $channel->push(CoroutineContext::get('key_a'));
            $channel->push(CoroutineContext::get('key_b'));
        });
        $parallel->wait();

        $this->assertSame('value_a', $channel->pop());
        $this->assertSame('value_b', $channel->pop());
    }

    public function testCopyContextEmptyArrayCopiesAllKeys()
    {
        CoroutineContext::set('key_a', 'value_a');
        CoroutineContext::set('key_b', 'value_b');

        $channel = new Channel(2);
        $parallel = new Parallel(0, copyContext: []);
        $parallel->add(function () use ($channel) {
            $channel->push(CoroutineContext::get('key_a'));
            $channel->push(CoroutineContext::get('key_b'));
        });
        $parallel->wait();

        $this->assertSame('value_a', $channel->pop());
        $this->assertSame('value_b', $channel->pop());
    }

    public function testCopyContextArrayCopiesSpecifiedKeysOnly()
    {
        CoroutineContext::set('key_a', 'value_a');
        CoroutineContext::set('key_b', 'value_b');

        $channel = new Channel(2);
        $parallel = new Parallel(0, copyContext: ['key_a']);
        $parallel->add(function () use ($channel) {
            $channel->push(CoroutineContext::get('key_a'));
            $channel->push(CoroutineContext::get('key_b'));
        });
        $parallel->wait();

        $this->assertSame('value_a', $channel->pop());
        $this->assertNull($channel->pop());
    }

    public function testCopyContextWorksWithConcurrencyLimit()
    {
        CoroutineContext::set('shared', 'value');

        $channel = new Channel(4);
        $parallel = new Parallel(2, copyContext: true);
        for ($i = 0; $i < 4; ++$i) {
            $parallel->add(function () use ($channel) {
                $channel->push(CoroutineContext::get('shared'));
            });
        }
        $parallel->wait();

        for ($i = 0; $i < 4; ++$i) {
            $this->assertSame('value', $channel->pop());
        }
    }

    public function testCopiedContextOmitsNonCopyableValues(): void
    {
        $resource = new class implements NonCopyableContext {
        };

        CoroutineContext::set('resource', $resource);
        CoroutineContext::set('request_id', 'abc');

        $results = parallel([
            static fn (): array => [
                CoroutineContext::get('resource'),
                CoroutineContext::get('request_id'),
            ],
        ], copyContext: true);

        $this->assertSame([[null, 'abc']], $results);
        $this->assertSame($resource, CoroutineContext::get('resource'));
    }

    public function testParallelHelperPassesCopyContextThrough()
    {
        CoroutineContext::set('via_helper', 'value');

        $channel = new Channel(1);
        parallel([
            function () use ($channel) {
                $channel->push(CoroutineContext::get('via_helper'));
            },
        ], 0, copyContext: true);

        $this->assertSame('value', $channel->pop());
    }

    public function testContextReplicationFailureDoesNotStrandParallelBookkeeping(): void
    {
        $result = new Channel(1);
        $runner = Coroutine::create(static function () use ($result): void {
            CoroutineContext::set('throwing', new ThrowingReplicableContext);
            $parallel = new Parallel(1, copyContext: true);
            $parallel->add(static fn (): string => 'never', 'first');
            $parallel->add(static fn (): string => 'never', 'second');

            try {
                $parallel->wait();
            } catch (ParallelExecutionException $exception) {
                $result->push($exception);
            }
        });

        $outcome = $result->pop(0.1);

        if ($outcome === false && Coroutine::exists($runner)) {
            SwooleCoroutine::cancel($runner, true);
        }

        $this->assertInstanceOf(ParallelExecutionException::class, $outcome);
        $this->assertSame(['first', 'second'], array_keys($outcome->getThrowables()));

        foreach ($outcome->getThrowables() as $throwable) {
            $this->assertSame('Unable to replicate context.', $throwable->getMessage());
        }
    }

    public function returnCoroutineId(): int
    {
        return Coroutine::id();
    }
}

class ParallelTestParallel extends Parallel
{
    public function closeConcurrencyChannelForTest(): void
    {
        $this->concurrentChannel?->close();
    }
}
