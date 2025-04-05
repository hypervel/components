<?php

declare(strict_types=1);

namespace Hypervel\Tests\Bus;

use Carbon\CarbonImmutable;
use Hyperf\Collection\Collection;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Query\Builder;
use Hypervel\Bus\Batch;
use Hypervel\Bus\Batchable;
use Hypervel\Bus\BatchFactory;
use Hypervel\Bus\DatabaseBatchRepository;
use Hypervel\Bus\Dispatchable;
use Hypervel\Bus\PendingBatch;
use Hypervel\Bus\Queueable;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Queue\CallQueuedClosure;
use Hypervel\Queue\Contracts\Factory;
use Hypervel\Queue\Contracts\Queue;
use Hypervel\Queue\Contracts\ShouldQueue;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class BusBatchTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['__finally.count'] = 0;
        $_SERVER['__progress.count'] = 0;
        $_SERVER['__then.count'] = 0;
        $_SERVER['__catch.count'] = 0;
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        unset($_SERVER['__finally.batch'], $_SERVER['__progress.batch'], $_SERVER['__then.batch'], $_SERVER['__catch.batch'], $_SERVER['__catch.exception']);

        m::close();
    }

    public function testJobsCanBeAddedToTheBatch()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $thirdJob = function () {};

        $queue->shouldReceive('connection')->once()
            ->with('test-connection')
            ->andReturn($connection = m::mock(Queue::class));

        $connection->shouldReceive('bulk')->once()->with(m::on(function ($args) use ($job, $secondJob) {
            return
                $args[0] == $job
                && $args[1] == $secondJob
                && $args[2] instanceof CallQueuedClosure
                && is_string($args[2]->batchId);
        }), '', 'test-queue');

        $batch = $batch->add([$job, $secondJob, $thirdJob]);

        $this->assertEquals(3, $batch->totalJobs);
        $this->assertEquals(3, $batch->pendingJobs);
        $this->assertIsString($job->batchId);
        $this->assertInstanceOf(CarbonImmutable::class, $batch->createdAt);
    }

    public function testJobsCanBeAddedToPendingBatch()
    {
        $batch = new PendingBatch($this->app, Collection::make());
        $this->assertCount(0, $batch->jobs);

        $job = new class {
            use Batchable;
        };
        $batch->add([$job]);
        $this->assertCount(1, $batch->jobs);

        $secondJob = new class {
            use Batchable;

            public $anotherProperty;
        };
        $batch->add($secondJob);
        $this->assertCount(2, $batch->jobs);
    }

    public function testJobsCanBeAddedToThePendingBatchFromIterable()
    {
        $batch = new PendingBatch($this->app, Collection::make());
        $this->assertCount(0, $batch->jobs);

        $count = 3;
        $generator = function (int $jobsCount) {
            for ($i = 0; $i < $jobsCount; ++$i) {
                yield new class {
                    use Batchable;
                };
            }
        };

        $batch->add($generator($count));
        $this->assertCount($count, $batch->jobs);
    }

    public function testProcessedJobsCanBeCalculated()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $batch->totalJobs = 10;
        $batch->pendingJobs = 4;

        $this->assertEquals(6, $batch->processedJobs());
        $this->assertEquals(60, $batch->progress());
    }

    public function testSuccessfulJobsCanBeRecorded()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $queue->shouldReceive('connection')->once()
            ->with('test-connection')
            ->andReturn($connection = m::mock(Queue::class));

        $connection->shouldReceive('bulk')->once();

        $batch = $batch->add([$job, $secondJob]);
        $this->assertEquals(2, $batch->pendingJobs);

        $batch->recordSuccessfulJob('test-id');
        $batch->recordSuccessfulJob('test-id');

        $this->assertInstanceOf(Batch::class, $_SERVER['__finally.batch']);
        $this->assertInstanceOf(Batch::class, $_SERVER['__progress.batch']);
        $this->assertInstanceOf(Batch::class, $_SERVER['__then.batch']);

        $batch = $batch->fresh();
        $this->assertEquals(0, $batch->pendingJobs);
        $this->assertTrue($batch->finished());
        $this->assertEquals(1, $_SERVER['__finally.count']);
        $this->assertEquals(2, $_SERVER['__progress.count']);
        $this->assertEquals(1, $_SERVER['__then.count']);
    }

    public function testFailedJobsCanBeRecordedWhileNotAllowingFailures()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue, $allowFailures = false);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $queue->shouldReceive('connection')->once()
            ->with('test-connection')
            ->andReturn($connection = m::mock(Queue::class));

        $connection->shouldReceive('bulk')->once();

        $batch = $batch->add([$job, $secondJob]);
        $this->assertEquals(2, $batch->pendingJobs);

        $batch->recordFailedJob('test-id', new RuntimeException('Something went wrong.'));
        $batch->recordFailedJob('test-id', new RuntimeException('Something else went wrong.'));

        $this->assertInstanceOf(Batch::class, $_SERVER['__finally.batch']);
        $this->assertFalse(isset($_SERVER['__then.batch']));

        $batch = $batch->fresh();
        $this->assertEquals(2, $batch->pendingJobs);
        $this->assertEquals(2, $batch->failedJobs);
        $this->assertTrue($batch->finished());
        $this->assertTrue($batch->cancelled());
        $this->assertEquals(1, $_SERVER['__finally.count']);
        $this->assertEquals(0, $_SERVER['__progress.count']);
        $this->assertEquals(1, $_SERVER['__catch.count']);
        $this->assertSame('Something went wrong.', $_SERVER['__catch.exception']->getMessage());
    }

    public function testFailedJobsCanBeRecordedWhileAllowingFailures()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue, $allowFailures = true);

        $job = new class {
            use Batchable;
        };

        $secondJob = new class {
            use Batchable;
        };

        $queue->shouldReceive('connection')->once()
            ->with('test-connection')
            ->andReturn($connection = m::mock(Queue::class));

        $connection->shouldReceive('bulk')->once();

        $batch = $batch->add([$job, $secondJob]);
        $this->assertEquals(2, $batch->pendingJobs);

        $batch->recordFailedJob('test-id', new RuntimeException('Something went wrong.'));
        $batch->recordFailedJob('test-id', new RuntimeException('Something else went wrong.'));

        // While allowing failures this batch never actually completes...
        $this->assertFalse(isset($_SERVER['__then.batch']));

        $batch = $batch->fresh();
        $this->assertEquals(2, $batch->pendingJobs);
        $this->assertEquals(2, $batch->failedJobs);
        $this->assertFalse($batch->finished());
        $this->assertFalse($batch->cancelled());
        $this->assertEquals(1, $_SERVER['__catch.count']);
        $this->assertEquals(2, $_SERVER['__progress.count']);
        $this->assertSame('Something went wrong.', $_SERVER['__catch.exception']->getMessage());
    }

    public function testBatchCanBeCancelled()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $batch->cancel();

        $batch = $batch->fresh();

        $this->assertTrue($batch->cancelled());
    }

    public function testBatchCanBeDeleted()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $batch->delete();

        $batch = $batch->fresh();

        $this->assertNull($batch);
    }

    public function testBatchStateCanBeInspected()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $this->assertFalse($batch->finished());
        $batch->finishedAt = now();
        $this->assertTrue($batch->finished());

        $batch->options['progress'] = [];
        $this->assertFalse($batch->hasProgressCallbacks());
        $batch->options['progress'] = [1];
        $this->assertTrue($batch->hasProgressCallbacks());

        $batch->options['then'] = [];
        $this->assertFalse($batch->hasThenCallbacks());
        $batch->options['then'] = [1];
        $this->assertTrue($batch->hasThenCallbacks());

        $this->assertFalse($batch->allowsFailures());
        $batch->options['allowFailures'] = true;
        $this->assertTrue($batch->allowsFailures());

        $this->assertFalse($batch->hasFailures());
        $batch->failedJobs = 1;
        $this->assertTrue($batch->hasFailures());

        $batch->options['catch'] = [];
        $this->assertFalse($batch->hasCatchCallbacks());
        $batch->options['catch'] = [1];
        $this->assertTrue($batch->hasCatchCallbacks());

        $this->assertFalse($batch->cancelled());
        $batch->cancelledAt = now();
        $this->assertTrue($batch->cancelled());

        $this->assertIsString(json_encode($batch));
    }

    public function testChainCanBeAddedToBatch()
    {
        $queue = m::mock(Factory::class);

        $batch = $this->createTestBatch($queue);

        $chainHeadJob = new ChainHeadJob();

        $secondJob = new SecondTestJob();

        $thirdJob = new ThirdTestJob();

        $queue->shouldReceive('connection')->once()
            ->with('test-connection')
            ->andReturn($connection = m::mock(Queue::class));

        $connection->shouldReceive('bulk')->once()->with(m::on(function ($args) use ($chainHeadJob, $secondJob, $thirdJob) {
            return
                $args[0] == $chainHeadJob
                && serialize($secondJob) == $args[0]->chained[0]
                && serialize($thirdJob) == $args[0]->chained[1];
        }), '', 'test-queue');

        $batch = $batch->add([
            [$chainHeadJob, $secondJob, $thirdJob],
        ]);

        $this->assertEquals(3, $batch->totalJobs);
        $this->assertEquals(3, $batch->pendingJobs);
        $this->assertSame('test-queue', $chainHeadJob->chainQueue);
        $this->assertIsString($chainHeadJob->batchId);
        $this->assertIsString($secondJob->batchId);
        $this->assertIsString($thirdJob->batchId);
        $this->assertInstanceOf(CarbonImmutable::class, $batch->createdAt);
    }

    public function testOptionsSerializationnPostgres()
    {
        $pendingBatch = (new PendingBatch($this->app, Collection::make()))
            ->onQueue('test-queue');

        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')
            ->andReturn('pgsql');
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')
            ->andReturn($connection);
        $builder = m::spy(Builder::class);

        $connection->shouldReceive('table')->andReturn($builder);
        $builder->shouldReceive('useWritePdo')->andReturnSelf();
        $builder->shouldReceive('where')->andReturnSelf();

        $repository = new DatabaseBatchRepository(
            new BatchFactory(m::mock(Factory::class)),
            $resolver,
            'job_batches'
        );

        $repository->store($pendingBatch);

        $builder->shouldHaveReceived('insert')
            ->withArgs(function ($argument) use ($pendingBatch) {
                return unserialize(base64_decode($argument['options'])) === $pendingBatch->options;
            });

        $builder->shouldHaveReceived('first');
    }

    #[DataProvider('serializedOptions')]
    public function testOptionsUnserializeOnPostgres($serialize, $options)
    {
        $factory = m::mock(BatchFactory::class);

        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')
            ->andReturn('pgsql');
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')
            ->andReturn($connection);

        $connection->shouldReceive('table->useWritePdo->where->first')
            ->andReturn($m = (object) [
                'id' => '',
                'name' => '',
                'total_jobs' => '',
                'pending_jobs' => '',
                'failed_jobs' => '',
                'failed_job_ids' => '[]',
                'options' => $serialize,
                'created_at' => now()->timestamp,
                'cancelled_at' => null,
                'finished_at' => null,
            ]);

        $batch = (new DatabaseBatchRepository($factory, $resolver, 'job_batches'));

        $factory->shouldReceive('make')
            ->withSomeOfArgs($batch, '', '', '', '', '', '', $options)
            ->andReturn(m::mock(Batch::class));

        $batch->find(1);
    }

    public static function serializedOptions(): array
    {
        $options = [1, 2];

        return [
            [serialize($options), $options],
            [base64_encode(serialize($options)), $options],
        ];
    }

    protected function createTestBatch($queue, $allowFailures = false)
    {
        $repository = new DatabaseBatchRepository(
            new BatchFactory($queue),
            $this->app->get(ConnectionResolverInterface::class),
            'job_batches'
        );

        $pendingBatch = (new PendingBatch($this->app, Collection::make()))
            ->progress(function (Batch $batch) {
                $_SERVER['__progress.batch'] = $batch;
                ++$_SERVER['__progress.count'];
            })
            ->then(function (Batch $batch) {
                $_SERVER['__then.batch'] = $batch;
                ++$_SERVER['__then.count'];
            })
            ->catch(function (Batch $batch, $e) {
                $_SERVER['__catch.batch'] = $batch;
                $_SERVER['__catch.exception'] = $e;
                ++$_SERVER['__catch.count'];
            })
            ->finally(function (Batch $batch) {
                $_SERVER['__finally.batch'] = $batch;
                ++$_SERVER['__finally.count'];
            })
            ->allowFailures($allowFailures)
            ->onConnection('test-connection')
            ->onQueue('test-queue');

        return $repository->store($pendingBatch);
    }
}

class ChainHeadJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use Batchable;
}

class SecondTestJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use Batchable;
}

class ThirdTestJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use Batchable;
}
