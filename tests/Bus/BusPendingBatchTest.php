<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchRepository;
use Hypervel\Bus\ChainedBatch;
use Hypervel\Bus\Events\BatchDispatched;
use Hypervel\Bus\PendingBatch;
use Hypervel\Bus\Queueable;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcher;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use stdClass;

class BusPendingBatchTest extends TestCase
{
    public function testPendingBatchNormalizesEnumConnectionAndQueueIdentifiers(): void
    {
        $job = new class {
            use Batchable;
        };

        $pendingBatch = new PendingBatch(new Container, new Collection([$job]));

        $pendingBatch
            ->onConnection(PendingBatchIntegerIdentifier::Zero)
            ->onQueue(PendingBatchUnitIdentifier::Primary);

        $this->assertSame('0', $pendingBatch->connection());
        $this->assertSame('Primary', $pendingBatch->queue());
    }

    public function testChainedBatchPreservesZeroConnectionAndQueueIdentifiers(): void
    {
        $container = new Container;
        Container::setInstance($container);

        $job = new class {
            use Batchable;
        };

        $source = (new PendingBatch($container, new Collection([$job])))
            ->onConnection(PendingBatchIntegerIdentifier::Zero)
            ->onQueue(PendingBatchIntegerIdentifier::Zero);

        $dispatcher = m::mock(BusDispatcher::class);
        $dispatcher->shouldReceive('batch')
            ->once()
            ->andReturnUsing(fn ($jobs) => new PendingBatch($container, $jobs));
        $container->instance(BusDispatcher::class, $dispatcher);

        $pendingBatch = (new ChainedBatch($source))->toPendingBatch();

        $this->assertSame('0', $pendingBatch->connection());
        $this->assertSame('0', $pendingBatch->queue());
    }

    public function testDirectlyRoutedChainedBatchPreservesZeroConnectionAndQueueIdentifiers(): void
    {
        $container = new Container;
        Container::setInstance($container);

        $job = new class {
            use Batchable;
        };

        $source = new PendingBatch($container, new Collection([$job]));
        $chainedBatch = (new ChainedBatch($source))
            ->onConnection('0')
            ->onQueue('0');

        $dispatcher = m::mock(BusDispatcher::class);
        $dispatcher->shouldReceive('batch')
            ->once()
            ->andReturnUsing(fn ($jobs) => new PendingBatch($container, $jobs));
        $container->instance(BusDispatcher::class, $dispatcher);

        $pendingBatch = $chainedBatch->toPendingBatch();

        $this->assertSame('0', $pendingBatch->connection());
        $this->assertSame('0', $pendingBatch->queue());
    }

    public function testChainedBatchPreservesSourceEmptyConnectionAndQueueOptions(): void
    {
        $container = new Container;
        Container::setInstance($container);

        $job = new class {
            use Batchable;
        };

        $source = (new PendingBatch($container, new Collection([$job])))
            ->onConnection('')
            ->onQueue('');

        $dispatcher = m::mock(BusDispatcher::class);
        $dispatcher->shouldReceive('batch')
            ->once()
            ->andReturnUsing(fn ($jobs) => new PendingBatch($container, $jobs));
        $container->instance(BusDispatcher::class, $dispatcher);

        $pendingBatch = (new ChainedBatch($source))->toPendingBatch();

        $this->assertSame('', $pendingBatch->connection());
        $this->assertSame('', $pendingBatch->queue());
    }

    public function testChainedBatchRemainderInheritsEmptyIdentifiersAndPreservesZeroIdentifiers(): void
    {
        foreach ([['', 'chain-connection', 'chain-queue'], ['0', '0', '0']] as [$route, $expectedConnection, $expectedQueue]) {
            $container = new Container;
            Container::setInstance($container);

            $sourceJob = new BatchableJob;
            $chainedBatch = new TestableChainedBatch(
                new PendingBatch($container, new Collection([$sourceJob]))
            );
            $chainedBatch->chain([
                (new ChainedBatchQueueableJob)->onConnection($route)->onQueue($route),
            ]);
            $chainedBatch->chainConnection = 'chain-connection';
            $chainedBatch->chainQueue = 'chain-queue';

            $dispatcher = m::mock(BusDispatcher::class);
            $dispatcher->shouldReceive('dispatch')->once()->with(m::on(function (ChainedBatchQueueableJob $job) use ($expectedConnection, $expectedQueue): bool {
                $this->assertSame($expectedConnection, $job->connection);
                $this->assertSame($expectedQueue, $job->queue);

                return true;
            }));
            $container->instance(BusDispatcher::class, $dispatcher);

            $pendingBatch = $chainedBatch->attachRemainder(
                new PendingBatch($container, new Collection([$sourceJob]))
            );

            $callbacks = $pendingBatch->finallyCallbacks();
            $this->assertCount(1, $callbacks);

            $batch = m::mock(Batch::class);
            $batch->shouldReceive('cancelled')->once()->andReturnFalse();
            $callbacks[0]($batch);
        }
    }

    public function testPendingBatchMayBeConfiguredAndDispatched()
    {
        $container = new Container;

        $eventDispatcher = m::mock(Dispatcher::class);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(BatchDispatched::class)->andReturnTrue();
        $eventDispatcher->shouldReceive('dispatch')->once()->with(m::type(BatchDispatched::class));

        $container->instance(Dispatcher::class, $eventDispatcher);

        $job = new class {
            use Batchable;
        };

        $pendingBatch = new PendingBatch($container, new Collection([$job]));

        $pendingBatch = $pendingBatch->before(function () {
        })->progress(function () {
        })->then(function () {
        })->catch(function () {
        })->allowFailures()->onConnection('test-connection')->onQueue('test-queue')->withOption('extra-option', 123);

        $this->assertSame('test-connection', $pendingBatch->connection());
        $this->assertSame('test-queue', $pendingBatch->queue());
        $this->assertCount(1, $pendingBatch->beforeCallbacks());
        $this->assertCount(1, $pendingBatch->progressCallbacks());
        $this->assertCount(1, $pendingBatch->thenCallbacks());
        $this->assertCount(1, $pendingBatch->catchCallbacks());
        $this->assertArrayHasKey('extra-option', $pendingBatch->options);
        $this->assertSame(123, $pendingBatch->options['extra-option']);

        $repository = m::mock(BatchRepository::class);
        $repository->shouldReceive('store')->once()->with($pendingBatch)->andReturn($batch = m::mock(Batch::class));
        $batch->shouldReceive('add')->once()->with(m::type(Collection::class))->andReturn($batch = m::mock(Batch::class));

        $container->instance(BatchRepository::class, $repository);

        $pendingBatch->dispatch();
    }

    public function testBatchDispatchedEventIsSkippedWithoutListeners(): void
    {
        $container = new Container;

        $eventDispatcher = m::mock(Dispatcher::class);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(BatchDispatched::class)->andReturnFalse();
        $eventDispatcher->shouldNotReceive('dispatch');
        $container->instance(Dispatcher::class, $eventDispatcher);

        $job = new class {
            use Batchable;
        };

        $pendingBatch = new PendingBatch($container, new Collection([$job]));

        $repository = m::mock(BatchRepository::class);
        $repository->shouldReceive('store')->once()->with($pendingBatch)->andReturn($batch = m::mock(Batch::class));
        $batch->shouldReceive('add')->once()->with(m::type(Collection::class))->andReturnSelf();
        $container->instance(BatchRepository::class, $repository);

        $this->assertSame($batch, $pendingBatch->dispatch());
    }

    public function testBatchDispatchedEventIsDispatchedAfterResponse(): void
    {
        $container = new Container;

        $eventDispatcher = m::mock(Dispatcher::class);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(BatchDispatched::class)->andReturnTrue();
        $eventDispatcher->shouldReceive('dispatch')->once()->with(m::type(BatchDispatched::class));
        $container->instance(Dispatcher::class, $eventDispatcher);

        $job = new class {
            use Batchable;
        };

        $pendingBatch = new PendingBatch($container, new Collection([$job]));

        $repository = m::mock(BatchRepository::class);
        $repository->shouldReceive('store')->once()->with($pendingBatch)->andReturn($batch = m::mock(Batch::class));
        $batch->shouldReceive('add')->once()->with(m::type(Collection::class))->andReturnSelf();
        $container->instance(BatchRepository::class, $repository);

        $this->assertSame($batch, $pendingBatch->dispatchAfterResponse());
    }

    public function testBatchIsDeletedFromStorageIfExceptionThrownDuringBatching()
    {
        $this->expectException(RuntimeException::class);

        $container = new Container;

        $job = new class {
            use Batchable;
        };

        $pendingBatch = new PendingBatch($container, new Collection([$job]));

        $repository = m::mock(BatchRepository::class);

        $repository->shouldReceive('store')->once()->with($pendingBatch)->andReturn($batch = m::mock(Batch::class));

        $batch->id = 'test-id';

        $batch->shouldReceive('add')->once()->andReturnUsing(function () {
            throw new RuntimeException('Failed to add jobs...');
        });

        $repository->shouldReceive('delete')->once()->with('test-id');

        $container->instance(BatchRepository::class, $repository);

        $pendingBatch->dispatch();
    }

    public function testBatchIsDispatchedWhenDispatchifIsTrue()
    {
        $container = new Container;

        $eventDispatcher = m::mock(Dispatcher::class);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(BatchDispatched::class)->andReturnTrue();
        $eventDispatcher->shouldReceive('dispatch')->once()->with(m::type(BatchDispatched::class));
        $container->instance(Dispatcher::class, $eventDispatcher);

        $job = new class {
            use Batchable;
        };

        $pendingBatch = new PendingBatch($container, new Collection([$job]));

        $repository = m::mock(BatchRepository::class);
        $repository->shouldReceive('store')->once()->andReturn($batch = m::mock(Batch::class));
        $batch->shouldReceive('add')->once()->andReturn($batch = m::mock(Batch::class));

        $container->instance(BatchRepository::class, $repository);

        $result = $pendingBatch->dispatchIf(true);

        $this->assertInstanceOf(Batch::class, $result);
    }

    public function testBatchIsNotDispatchedWhenDispatchifIsFalse()
    {
        $container = new Container;

        $eventDispatcher = m::mock(Dispatcher::class);
        $eventDispatcher->shouldNotReceive('dispatch');
        $container->instance(Dispatcher::class, $eventDispatcher);

        $job = new class {
            use Batchable;
        };

        $pendingBatch = new PendingBatch($container, new Collection([$job]));

        $repository = m::mock(BatchRepository::class);
        $container->instance(BatchRepository::class, $repository);

        $result = $pendingBatch->dispatchIf(false);

        $this->assertNull($result);
    }

    public function testBatchIsDispatchedWhenDispatchunlessIsFalse()
    {
        $container = new Container;

        $eventDispatcher = m::mock(Dispatcher::class);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(BatchDispatched::class)->andReturnTrue();
        $eventDispatcher->shouldReceive('dispatch')->once()->with(m::type(BatchDispatched::class));
        $container->instance(Dispatcher::class, $eventDispatcher);

        $job = new class {
            use Batchable;
        };

        $pendingBatch = new PendingBatch($container, new Collection([$job]));

        $repository = m::mock(BatchRepository::class);
        $repository->shouldReceive('store')->once()->andReturn($batch = m::mock(Batch::class));
        $batch->shouldReceive('add')->once()->andReturn($batch = m::mock(Batch::class));

        $container->instance(BatchRepository::class, $repository);

        $result = $pendingBatch->dispatchUnless(false);

        $this->assertInstanceOf(Batch::class, $result);
    }

    public function testBatchIsNotDispatchedWhenDispatchunlessIsTrue()
    {
        $container = new Container;

        $eventDispatcher = m::mock(Dispatcher::class);
        $eventDispatcher->shouldNotReceive('dispatch');
        $container->instance(Dispatcher::class, $eventDispatcher);

        $job = new class {
            use Batchable;
        };

        $pendingBatch = new PendingBatch($container, new Collection([$job]));

        $repository = m::mock(BatchRepository::class);
        $container->instance(BatchRepository::class, $repository);

        $result = $pendingBatch->dispatchUnless(true);

        $this->assertNull($result);
    }

    public function testBatchBeforeEventIsCalled()
    {
        $container = new Container;

        $eventDispatcher = m::mock(Dispatcher::class);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(BatchDispatched::class)->andReturnTrue();
        $eventDispatcher->shouldReceive('dispatch')->once()->with(m::type(BatchDispatched::class));

        $container->instance(Dispatcher::class, $eventDispatcher);

        $job = new class {
            use Batchable;
        };

        $beforeCalled = false;

        $pendingBatch = new PendingBatch($container, new Collection([$job]));

        $pendingBatch = $pendingBatch->before(function () use (&$beforeCalled) {
            $beforeCalled = true;
        })->onConnection('test-connection')->onQueue('test-queue');

        $repository = m::mock(BatchRepository::class);
        $repository->shouldReceive('store')->once()->with($pendingBatch)->andReturn($batch = m::mock(Batch::class));
        $batch->shouldReceive('add')->once()->with(m::type(Collection::class))->andReturn($batch = m::mock(Batch::class));

        $container->instance(BatchRepository::class, $repository);

        $pendingBatch->dispatch();

        $this->assertTrue($beforeCalled);
    }

    public function testItThrowsExceptionIfBatchedJobIsNotBatchable()
    {
        $nonBatchableJob = new class {
        };

        $this->expectException(RuntimeException::class);

        new PendingBatch(new Container, new Collection([$nonBatchableJob]));
    }

    public function testItThrowsAnExceptionIfBatchedJobContainsBatchWithNonbatchableJob()
    {
        $this->expectException(RuntimeException::class);

        $container = new Container;
        new PendingBatch(
            $container,
            new Collection(
                [new PendingBatch($container, new Collection([new BatchableJob, new class {
                }]))]
            )
        );
    }

    public function testItCanBatchAClosure()
    {
        new PendingBatch(
            new Container,
            new Collection([
                function () {
                },
            ])
        );
        $this->expectNotToPerformAssertions();
    }

    public function testAllowFailuresWithBooleanTrueEnablesFailureTolerance()
    {
        $batch = new PendingBatch(new Container, new Collection([new BatchableJob]));

        $result = $batch->allowFailures(true);

        $this->assertSame($batch, $result);
        $this->assertTrue($batch->options['allowFailures']);
        $this->assertEmpty($batch->failureCallbacks());
    }

    public function testAllowFailuresWithBooleanFalseDisablesFailureTolerance()
    {
        $batch = new PendingBatch(new Container, new Collection([new BatchableJob]));

        $result = $batch->allowFailures(false);

        $this->assertSame($batch, $result);
        $this->assertFalse($batch->options['allowFailures']);
        $this->assertEmpty($batch->failureCallbacks());
    }

    public function testAllowFailuresWithSingleClosureRegistersCallback()
    {
        $batch = new PendingBatch(new Container, new Collection([new BatchableJob]));

        $result = $batch->allowFailures(static fn (): true => true);

        $this->assertSame($batch, $result);
        $this->assertTrue($batch->options['allowFailures']);
        $this->assertCount(1, $batch->failureCallbacks());
    }

    public function testAllowFailuresWithSingleCallableRegistersCallback()
    {
        $batch = new PendingBatch(new Container, new Collection([new BatchableJob]));

        $result = $batch->allowFailures('strlen');

        $this->assertSame($batch, $result);
        $this->assertTrue($batch->options['allowFailures']);
        $this->assertCount(1, $batch->failureCallbacks());
    }

    public function testAllowFailuresWithArrayOfCallablesRegistersMultipleCallbacks()
    {
        $batch = new PendingBatch(new Container, new Collection([new BatchableJob]));

        $result = $batch->allowFailures([
            static fn (): true => true,
            'strlen',
            [$batch, 'failureCallbacks'],
            strlen(...),
        ]);

        $this->assertSame($batch, $result);
        $this->assertTrue($batch->options['allowFailures']);
        $this->assertCount(4, $batch->failureCallbacks());
    }

    public function testAllowFailuresRegistersOnlyValidCallbacks()
    {
        $batch = new PendingBatch(new Container, new Collection([new BatchableJob]));

        $result = $batch->allowFailures([
            // 3 valid
            static fn (): true => true,
            'strlen',
            [$batch, 'failureCallbacks'],
            // 5 invalid
            'invalid_function_name',
            123,
            null,
            [],
            new stdClass,
        ]);

        $this->assertSame($batch, $result);
        $this->assertTrue($batch->options['allowFailures']);
        $this->assertCount(3, $batch->failureCallbacks());
    }

    public function testAllowFailuresWithEmptyArrayEnablesToleranceWithoutCallbacks()
    {
        $batch = new PendingBatch(new Container, new Collection([new BatchableJob]));

        $result = $batch->allowFailures([]);

        $this->assertSame($batch, $result);
        $this->assertTrue($batch->options['allowFailures']);
        $this->assertEmpty($batch->failureCallbacks());
    }

    public function testAllowFailuresIsChainable()
    {
        $batch = new PendingBatch(new Container, new Collection([new BatchableJob]));

        $this->assertSame($batch, $batch->allowFailures(true));
        $this->assertSame($batch, $batch->allowFailures(false));
        $this->assertSame($batch, $batch->allowFailures(static fn (): true => true));
        $this->assertSame($batch, $batch->allowFailures('strlen'));
        $this->assertSame($batch, $batch->allowFailures([static fn (): true => true, 'strlen']));
        $this->assertSame($batch, $batch->allowFailures([]));
    }

    public function testFailureCallbacksAccessorReturnsRegisteredCallbacks()
    {
        $batch = new PendingBatch(new Container, new Collection([new BatchableJob]));

        $this->assertEmpty($batch->failureCallbacks());

        $batch->allowFailures(
            static fn (): true => true
        );

        $this->assertCount(1, $batch->failureCallbacks());

        $freshBatch = new PendingBatch(new Container, new Collection([new BatchableJob]));

        $freshBatch->allowFailures([
            'strlen',
            [$freshBatch, 'failureCallbacks'],
        ]);

        $this->assertCount(2, $freshBatch->failureCallbacks());

        $anotherBatch = new PendingBatch(new Container, new Collection([new BatchableJob]));

        $anotherBatch->allowFailures([
            static fn (): false => false,
            'trim',
            123,
            'invalid_function',
        ]);

        $this->assertCount(2, $anotherBatch->failureCallbacks());
    }
}

enum PendingBatchIntegerIdentifier: int
{
    case Zero = 0;
}

enum PendingBatchUnitIdentifier
{
    case Primary;
}

class BatchableJob
{
    use Batchable;
}

class ChainedBatchQueueableJob
{
    use Queueable;
}

class TestableChainedBatch extends ChainedBatch
{
    public function attachRemainder(PendingBatch $batch): PendingBatch
    {
        return $this->attachRemainderOfChainToEndOfBatch($batch);
    }
}
