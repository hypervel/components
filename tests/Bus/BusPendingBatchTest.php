<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\PendingBatch;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\BatchRepository;
use Hypervel\Support\Collection;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Hypervel\Contracts\Event\Dispatcher;
use RuntimeException;
use TypeError;

enum PendingBatchTestConnectionEnum: string
{
    case Redis = 'redis';
    case Database = 'database';
}

enum PendingBatchTestConnectionUnitEnum
{
    case sync;
    case async;
}

enum PendingBatchTestConnectionIntEnum: int
{
    case Primary = 1;
}

/**
 * @internal
 * @coversNothing
 */
class BusPendingBatchTest extends TestCase
{
    public function testPendingBatchMayBeConfiguredAndDispatched()
    {
        $container = $this->getContainer();

        $eventDispatcher = m::mock(Dispatcher::class);
        $eventDispatcher->shouldReceive('dispatch')->once();

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

    public function testBatchIsDeletedFromStorageIfExceptionThrownDuringBatching()
    {
        $this->expectException(RuntimeException::class);

        $container = $this->getContainer();

        $job = new class {};

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
        $container = $this->getContainer();

        $eventDispatcher = m::mock(Dispatcher::class);
        $eventDispatcher->shouldReceive('dispatch')->once();
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
        $container = $this->getContainer();

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

    public function testBatchIsDispatchedWhenDispatchUnlessIsFalse()
    {
        $container = $this->getContainer();

        $eventDispatcher = m::mock(Dispatcher::class);
        $eventDispatcher->shouldReceive('dispatch')->once();
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

    public function testBatchIsNotDispatchedWhenDispatchUnlessIsTrue()
    {
        $container = $this->getContainer();

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
        $container = $this->getContainer();

        $eventDispatcher = m::mock(Dispatcher::class);
        $eventDispatcher->shouldReceive('dispatch')->once();

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

    public function testOnConnectionAcceptsStringBackedEnum(): void
    {
        $container = $this->getContainer();
        $pendingBatch = new PendingBatch($container, new Collection([]));

        $pendingBatch->onConnection(PendingBatchTestConnectionEnum::Redis);

        $this->assertSame('redis', $pendingBatch->connection());
    }

    public function testOnConnectionAcceptsUnitEnum(): void
    {
        $container = $this->getContainer();
        $pendingBatch = new PendingBatch($container, new Collection([]));

        $pendingBatch->onConnection(PendingBatchTestConnectionUnitEnum::sync);

        $this->assertSame('sync', $pendingBatch->connection());
    }

    public function testOnConnectionWithIntBackedEnumThrowsTypeError(): void
    {
        $this->expectException(TypeError::class);

        $container = $this->getContainer();
        $pendingBatch = new PendingBatch($container, new Collection([]));

        $pendingBatch->onConnection(PendingBatchTestConnectionIntEnum::Primary);
        $pendingBatch->connection(); // TypeError thrown here on return type mismatch
    }

    protected function getContainer(array $bindings = []): Container
    {
        $container = new Container();

        foreach ($bindings as $abstract => $concrete) {
            $container->instance($abstract, $concrete);
        }

        return $container;
    }
}
