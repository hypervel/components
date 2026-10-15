<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Feature;

use Hypervel\ConnectionPool\Events\ConnectionReleasing;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Pool\ConnectionLease;
use Hypervel\Database\Pool\PoolManager;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Scout\Console\ConcurrentImportRunner;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class ConcurrentImportConnectionTest extends TestCase
{
    /**
     * Share one physical slot between the chunk reader and import operations.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.connections.import', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'pool' => [
                'testing_enabled' => true,
                'max_connections' => 1,
                'wait_timeout' => 0.1,
                'events' => [ConnectionReleasing::class],
            ],
        ]);
    }

    public function testParentRetainsItsConnectionUntilJoiningChildren(): void
    {
        $parent = DB::connection('import');
        $parent->selectOne('select 1');
        $pool = $this->app->make(PoolManager::class)->pool('import');
        $runner = new ConcurrentImportRunner(1);
        $value = null;

        $runner->create(static function () use (&$value): void {
            $value = DB::connection('import')->selectOne('select 7 as value')->value;
        });
        $this->assertSame(1, $pool->getBorrowedCount());
        $this->assertNull($value);

        $runner->wait();

        $this->assertSame(7, $value);
        $this->assertSame(0, $pool->getBorrowedCount());
        $this->assertSame(1, $parent->selectOne('select 1 as value')->value);
    }

    public function testChildReturnsItsConnectionBeforeTheNextSlotAndDeferredCleanup(): void
    {
        $runner = new ConcurrentImportRunner(1);
        $finishCleanup = new Channel(1);
        $values = [];
        $secondCompleted = new Channel(1);
        $runner->create(static function () use ($finishCleanup, &$values): void {
            $values[] = DB::connection('import')->selectOne('select 1 as value')->value;
            // Register after acquiring the session so this callback precedes its deferred release.
            Coroutine::defer(static fn () => $finishCleanup->pop());
        });

        try {
            $runner->create(static function () use (&$values, $secondCompleted): void {
                $values[] = DB::connection('import')->selectOne('select 2 as value')->value;
                $secondCompleted->push(true);
            });
            $this->assertTrue($secondCompleted->pop(1));
        } finally {
            $finishCleanup->push(true);
            $runner->wait();
        }

        $this->assertSame([1, 2], $values);
    }

    public function testFailedChildReturnsItsIdleConnectionBeforeDeferredCleanup(): void
    {
        $runner = new ConcurrentImportRunner(1);
        $finishCleanup = new Channel(1);
        $failure = new RuntimeException('Indexing failed.');
        $pool = $this->app->make(PoolManager::class)->pool('import');

        $cleanupStarted = new Channel(1);
        try {
            $runner->create(static function () use ($finishCleanup, $cleanupStarted, $failure): void {
                DB::connection('import')->selectOne('select 1');
                Coroutine::defer(static function () use ($finishCleanup, $cleanupStarted): void {
                    $cleanupStarted->push(true);
                    $finishCleanup->pop();
                });
                throw $failure;
            });
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        try {
            $this->assertTrue($cleanupStarted->pop());
            $this->assertSame(0, $pool->getBorrowedCount());
        } finally {
            $finishCleanup->push(true);
        }

        $this->expectExceptionObject($failure);
        $runner->wait();
    }

    public function testCancellationDuringParentReleaseCancelsChildrenWithoutDrainingThem(): void
    {
        $releasing = new Channel(1);
        $releaseBlocker = new Channel(1);
        $childBlocker = new Channel(1);
        $cleanupBlocker = new Channel(1);
        $childFinished = new Channel(1);
        $childStarted = new Channel(1);
        $parentFinished = new Channel(1);
        $childCanceled = false;
        $parentCancellation = null;
        Event::listen(ConnectionReleasing::class, static function () use ($releasing, $releaseBlocker): void {
            $releasing->push(true);
            $releaseBlocker->pop();
        });

        $parent = EngineCoroutine::create(static function () use (
            $childBlocker,
            $cleanupBlocker,
            $childFinished,
            $childStarted,
            $parentFinished,
            &$childCanceled,
            &$parentCancellation,
        ): void {
            DB::connection('import')->selectOne('select 1');
            $runner = new ConcurrentImportRunner(1);
            $runner->create(static function () use ($childBlocker, $cleanupBlocker, $childFinished, $childStarted, &$childCanceled): void {
                Coroutine::defer(static function () use ($cleanupBlocker, $childFinished): void {
                    $cleanupBlocker->pop();
                    $childFinished->push(true);
                });
                try {
                    $childStarted->push(true);
                    $childBlocker->pop();
                } catch (CanceledException $exception) {
                    $childCanceled = true;
                    throw $exception;
                }
            });
            $childStarted->pop();
            try {
                $runner->wait();
            } catch (CanceledException $exception) {
                $parentCancellation = $exception;
            } finally {
                $parentFinished->push(true);
            }
        });

        try {
            $this->assertTrue($releasing->pop(1));
            $this->assertTrue(EngineCoroutine::cancelById($parent->getId(), throwException: true));
            $this->assertTrue($parentFinished->pop(1));
            $this->assertTrue($childCanceled);
            $this->assertInstanceOf(CanceledException::class, $parentCancellation);
        } finally {
            $releaseBlocker->push(true);
            $childBlocker->push(true);
            $cleanupBlocker->push(true);
            Event::forget(ConnectionReleasing::class);
        }

        $this->assertTrue($childFinished->pop(1));
    }

    public function testOrdinaryParentReleaseFailureWaitsForChildrenBeforeRethrowing(): void
    {
        $releaseAttempted = new Channel(1);
        $childBlocker = new Channel(1);
        $parentFinished = new Channel(1);
        $failure = new RuntimeException('Session cleanup failed.');
        $childCompleted = false;
        $observed = null;
        $lease = m::mock(ConnectionLease::class);
        $lease->expects('releaseIfIdle')->andReturnUsing(static function () use ($releaseAttempted, $failure): void {
            $releaseAttempted->push(true);
            throw $failure;
        });
        EngineCoroutine::create(static function () use (
            $lease,
            $childBlocker,
            $parentFinished,
            &$childCompleted,
            &$observed,
        ): void {
            CoroutineContext::set('test.import.lease', $lease);
            $runner = new ConcurrentImportRunner(1);
            $runner->create(static function () use ($childBlocker, &$childCompleted): void {
                $childBlocker->pop();
                $childCompleted = true;
            });
            try {
                $runner->wait();
            } catch (RuntimeException $exception) {
                $observed = $exception;
            } finally {
                CoroutineContext::forget('test.import.lease');
                $parentFinished->push(true);
            }
        });

        try {
            $this->assertTrue($releaseAttempted->pop(1));
            $this->assertNull($observed);
            $this->assertFalse($childCompleted);
        } finally {
            $childBlocker->push(true);
        }
        $this->assertTrue($parentFinished->pop(1));
        $this->assertTrue($childCompleted);
        $this->assertSame($failure, $observed);
    }
}
