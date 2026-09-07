<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use BadMethodCallException;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Bus\Queueable;
use Hypervel\Bus\UniqueLock;
use Hypervel\Cache\Repository;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Contracts\Cache\Repository as CacheContract;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Foundation\Application;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\CallQueuedClosure;
use Hypervel\Queue\Jobs\InspectedJob;
use Hypervel\Queue\QueueManager;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\ExpectationFailedException;
use RuntimeException;

class SupportTestingQueueFakeTest extends TestCase
{
    private QueueFake $fake;

    private JobStub $job;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new QueueFake(new Application);
        $this->job = new JobStub;
    }

    public function testAssertPushed(): void
    {
        try {
            $this->fake->assertPushed(JobStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\JobStub] job was not pushed.', $e->getMessage());
        }

        $this->fake->push($this->job);

        $this->fake->assertPushed(JobStub::class);
    }

    public function testItCanAssertAgainstDataWithPush(): void
    {
        $data = null;
        $this->fake->push(JobStub::class, ['foo' => 'bar'], 'redis');

        $this->fake->assertPushed(JobStub::class, function ($job, $queue, $jobData) use (&$data) {
            $data = $jobData;

            return true;
        });

        $this->assertSame(['foo' => 'bar'], $data);
    }

    public function testAssertPushedWithIgnore(): void
    {
        $job = new JobStub;

        $queue = m::mock(Queue::class);
        $queue->shouldReceive('push')->once()->withArgs(function ($passedJob) use ($job) {
            return $passedJob === $job;
        });
        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->once()->with(null)->andReturn($queue);

        $fake = new QueueFake(new Application, JobToFakeStub::class, $manager);

        $fake->push($job);
        $fake->push(new JobToFakeStub);

        $fake->assertNotPushed(JobStub::class);
        $fake->assertPushed(JobToFakeStub::class);
    }

    public function testAssertPushedWithClosure(): void
    {
        $this->fake->push($this->job);

        $this->fake->assertPushed(function (JobStub $job) {
            return true;
        });
    }

    public function testQueueSize(): void
    {
        $this->assertEquals(0, $this->fake->size());

        $this->fake->push($this->job);

        $this->assertEquals(1, $this->fake->size());
    }

    public function testQueueSizeAcceptsUnitEnums(): void
    {
        $this->fake->push($this->job, '', QueueNameEnumStub::Foo);

        $this->assertEquals(1, $this->fake->size('foo'));
        $this->assertEquals(1, $this->fake->size(QueueNameEnumStub::Foo));
        $this->assertEquals(0, $this->fake->size(QueueNameEnumStub::Bar));
    }

    public function testAssertNotPushed(): void
    {
        $this->fake->push($this->job);

        try {
            $this->fake->assertNotPushed(JobStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The unexpected [Hypervel\Tests\Support\JobStub] job was pushed.', $e->getMessage());
        }
    }

    public function testAssertNotPushedWithClosure(): void
    {
        $this->fake->assertNotPushed(JobStub::class);

        $this->fake->push($this->job);

        try {
            $this->fake->assertNotPushed(function (JobStub $job) {
                return true;
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The unexpected [Hypervel\Tests\Support\JobStub] job was pushed.', $e->getMessage());
        }
    }

    public function testAssertPushedOn(): void
    {
        $this->fake->push($this->job, '', 'foo');

        try {
            $this->fake->assertPushedOn('bar', JobStub::class);
            $this->fake->assertPushedOn(QueueNameEnumStub::Bar, JobStub::class);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\JobStub] job was not pushed.', $e->getMessage());
        }

        $this->fake->assertPushedOn('foo', JobStub::class);
        $this->fake->assertPushedOn(QueueNameEnumStub::Foo, JobStub::class);
    }

    public function testAssertPushedOnWithClosure(): void
    {
        $this->fake->push($this->job, '', 'foo');

        try {
            $this->fake->assertPushedOn('bar', function (JobStub $job) {
                return true;
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\JobStub] job was not pushed.', $e->getMessage());
        }

        $this->fake->assertPushedOn('foo', function (JobStub $job) {
            return true;
        });
    }

    public function testAssertPushedTimes(): void
    {
        $this->fake->push($this->job);
        $this->fake->push($this->job);

        try {
            $this->fake->assertPushed(JobStub::class, 1);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\JobStub] job was pushed 2 times instead of 1 time.', $e->getMessage());
        }

        $this->fake->assertPushed(JobStub::class, 2);
    }

    public function testAssertCount(): void
    {
        $this->fake->push(function () {
            // Do nothing
        });

        $this->fake->push($this->job);
        $this->fake->push($this->job);

        $this->fake->assertCount(3);
    }

    public function testAssertNothingPushed(): void
    {
        $this->fake->assertNothingPushed();

        $this->fake->push($this->job);

        $this->fake->push(function () {
        });

        try {
            $this->fake->assertNothingPushed();
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The following jobs were pushed unexpectedly', $e->getMessage());
            $this->assertStringContainsString(get_class($this->job), $e->getMessage());
            $this->assertStringContainsString(CallQueuedClosure::class, $e->getMessage());
        }
    }

    public function testAssertPushedUsingBulk(): void
    {
        $this->fake->assertNothingPushed();

        $queue = QueueNameEnumStub::Foo;
        $this->fake->bulk([
            $this->job,
            new JobStub,
        ], null, $queue);

        $this->fake->assertPushedOn('foo', JobStub::class);
        $this->fake->assertPushedOn($queue, JobStub::class);
        $this->fake->assertPushed(JobStub::class, 2);
    }

    public function testBulkRespectsDelayAttribute(): void
    {
        $this->fake->bulk([
            new JobWithDelayAttributeStub,
            new JobStub,
        ], ['foo' => 'bar'], 'redis');

        $this->assertSame(1, $this->fake->delayedSize('redis'));
        $this->fake->assertPushedOn('redis', JobWithDelayAttributeStub::class);
        $this->fake->assertPushed(JobWithDelayAttributeStub::class, function (JobWithDelayAttributeStub $job, ?string $queue, mixed $data): bool {
            return $queue === 'redis' && $data === ['foo' => 'bar'];
        });
        $this->fake->assertPushedOn('redis', JobStub::class);
    }

    public function testBulkRespectsRuntimeDelay(): void
    {
        $job = (new JobWithRuntimeDelayStub)->delay(30);

        $this->fake->bulk([$job], '', 'redis');

        $this->assertSame(1, $this->fake->delayedSize('redis'));
        $this->fake->assertPushedOn('redis', JobWithRuntimeDelayStub::class);
    }

    public function testPushOnAndLaterOnAcceptUnitEnums(): void
    {
        $this->fake->pushOn(QueueNameEnumStub::Foo, $this->job);
        $this->fake->laterOn(QueueNameEnumStub::Bar, 10, new JobToFakeStub);

        $this->fake->assertPushedOn('foo', JobStub::class);
        $this->fake->assertPushedOn('bar', JobToFakeStub::class);
    }

    public function testAssertPushedWithChainUsingClassesOrObjectsArray(): void
    {
        $this->fake->push(new JobWithChainStub([
            new JobStub,
        ]));

        $this->fake->assertPushedWithChain(JobWithChainStub::class, [
            JobStub::class,
        ]);

        $this->fake->assertPushedWithChain(JobWithChainStub::class, [
            new JobStub,
        ]);
    }

    public function testAssertPushedWithoutChain(): void
    {
        $this->fake->push(new JobWithChainStub([]));

        $this->fake->assertPushedWithoutChain(JobWithChainStub::class);
    }

    public function testAssertPushedWithChainSameJobDifferentChains(): void
    {
        $this->fake->push(new JobWithChainStub([
            new JobStub,
        ]));
        $this->fake->push(new JobWithChainStub([
            new JobStub,
            new JobStub,
        ]));

        $this->fake->assertPushedWithChain(JobWithChainStub::class, [
            JobStub::class,
        ]);

        $this->fake->assertPushedWithChain(JobWithChainStub::class, [
            JobStub::class,
            JobStub::class,
        ]);
    }

    public function testAssertPushedWithChainUsingCallback(): void
    {
        $this->fake->push(new JobWithChainAndParameterStub('first', [
            new JobStub,
            new JobStub,
        ]));

        $this->fake->push(new JobWithChainAndParameterStub('second', [
            new JobStub,
        ]));

        $this->fake->assertPushedWithChain(JobWithChainAndParameterStub::class, [
            JobStub::class,
        ], function ($job) {
            return $job->parameter === 'second';
        });

        try {
            $this->fake->assertPushedWithChain(JobWithChainAndParameterStub::class, [
                JobStub::class,
                JobStub::class,
            ], function ($job) {
                return $job->parameter === 'second';
            });
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected chain was not pushed.', $e->getMessage());
        }
    }

    public function testAssertPushedWithChainErrorHandling(): void
    {
        try {
            $this->fake->assertPushedWithChain(JobWithChainStub::class, []);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected [Hypervel\Tests\Support\JobWithChainStub] job was not pushed.', $e->getMessage());
        }

        $this->fake->push(new JobWithChainStub([
            new JobStub,
        ]));

        try {
            $this->fake->assertPushedWithChain(JobWithChainStub::class, []);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected chain can not be empty.', $e->getMessage());
        }

        try {
            $this->fake->assertPushedWithChain(JobWithChainStub::class, [
                new JobStub,
                new JobStub,
            ]);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected chain was not pushed.', $e->getMessage());
        }

        try {
            $this->fake->assertPushedWithChain(JobWithChainStub::class, [
                JobStub::class,
                JobStub::class,
            ]);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected chain was not pushed.', $e->getMessage());
        }
    }

    public function testCallUndefinedMethodErrorHandling(): void
    {
        try {
            $this->fake->undefinedMethod();
        } catch (BadMethodCallException $e) {
            $this->assertSame(sprintf(
                'Call to undefined method %s::%s()',
                get_class($this->fake),
                'undefinedMethod'
            ), $e->getMessage());
        }
    }

    public function testAssertClosurePushed(): void
    {
        $this->fake->push(function () {
            // Do nothing
        });

        $this->fake->assertClosurePushed();
    }

    public function testAssertClosurePushedWithTimes(): void
    {
        $this->fake->push(function () {
            // Do nothing
        });

        $this->fake->push(function () {
            // Do nothing
        });

        $this->fake->assertClosurePushed(2);
    }

    public function testAssertClosureNotPushed(): void
    {
        $this->fake->push($this->job);

        $this->fake->assertClosureNotPushed();
    }

    public function testItDoesntFakeJobsPassedViaExcept(): void
    {
        $job = new JobStub;

        $queue = m::mock(Queue::class);
        $queue->shouldReceive('push')->once()->withArgs(function ($passedJob) use ($job) {
            return $passedJob === $job;
        });
        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->once()->with(null)->andReturn($queue);

        $fake = (new QueueFake(new Application, [], $manager))->except(JobStub::class);

        $fake->push($job);
        $fake->push(new JobToFakeStub);

        $fake->assertNotPushed(JobStub::class);
        $fake->assertPushed(JobToFakeStub::class);
    }

    public function testItCanSerializeAndRestoreJobs(): void
    {
        // confirm that the default behavior is maintained
        $this->fake->push(new JobWithSerialization('hello'));
        $this->fake->assertPushed(JobWithSerialization::class, fn ($job) => $job->value === 'hello');

        $job = new JobWithSerialization('hello');

        $fake = new QueueFake(new Application);
        $fake->serializeAndRestore();
        $fake->push($job);

        $fake->assertPushed(
            JobWithSerialization::class,
            fn ($job) => $job->value === 'hello-serialized-unserialized'
        );
    }

    public function testFakedUniqueJobAcceptsDispatchOwnershipUntilFakeCleanup(): void
    {
        $application = new Application;
        $cache = new Repository(new WorkerArrayStore);
        $application->instance(CacheContract::class, $cache);
        $fake = new QueueFake($application);
        $job = new QueueFakeUniqueJobStub;
        $lock = new UniqueLock($cache);

        $this->assertTrue($lock->acquireForDispatch($job));
        $metadata = DispatchLockContext::peekPayloadMetadata($job);
        $this->assertNotNull($metadata);

        $fake->push($job);

        $this->assertNull(DispatchLockContext::peekPayloadMetadata($job));
        $this->assertFalse($lock->acquire(new QueueFakeUniqueJobStub));

        $fake->releaseUniqueJobLocks();

        $this->assertTrue($lock->acquire(new QueueFakeUniqueJobStub));
    }

    public function testItCanInvokeCallbacksBeforeAndAfterPushingFakedJobs(): void
    {
        $steps = [];

        $this->fake->beforePushing(function ($job, $data, $queue) use (&$steps) {
            $steps[] = ['before', is_object($job) ? get_class($job) : $job, $data, $queue];
        });

        $this->fake->beforePushing(function ($job, $data, $queue) use (&$steps) {
            $steps[] = ['before again', is_object($job) ? get_class($job) : $job, $data, $queue];
        });

        $this->fake->afterPushing(function ($job, $data, $queue) use (&$steps) {
            $steps[] = ['after', is_object($job) ? get_class($job) : $job, $data, $queue];
        });

        $this->fake->afterPushing(function ($job, $data, $queue) use (&$steps) {
            $steps[] = ['after again', is_object($job) ? get_class($job) : $job, $data, $queue];
        });

        $this->fake->push($this->job, ['foo' => 'bar'], 'redis');

        $this->assertSame([
            ['before', JobStub::class, ['foo' => 'bar'], 'redis'],
            ['before again', JobStub::class, ['foo' => 'bar'], 'redis'],
            ['after', JobStub::class, ['foo' => 'bar'], 'redis'],
            ['after again', JobStub::class, ['foo' => 'bar'], 'redis'],
        ], $steps);
    }

    public function testItCanInvokeCallbacksBeforeAndAfterPushingDispatchedJobs(): void
    {
        $job = new JobStub;
        $steps = [];

        $queue = m::mock(Queue::class);
        $queue->shouldReceive('push')->once()->withArgs(function ($passedJob, $passedData, $passedQueue) use ($job) {
            return $passedJob === $job && $passedData === ['foo' => 'bar'] && $passedQueue === 'redis';
        });
        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->once()->with(null)->andReturn($queue);

        $fake = (new QueueFake(new Application, [], $manager))
            ->except(JobStub::class)
            ->beforePushing(function ($job, $data, $queue) use (&$steps) {
                $steps[] = ['before', is_object($job) ? get_class($job) : $job, $data, $queue];
            })
            ->beforePushing(function ($job, $data, $queue) use (&$steps) {
                $steps[] = ['before again', is_object($job) ? get_class($job) : $job, $data, $queue];
            })
            ->afterPushing(function ($job, $data, $queue) use (&$steps) {
                $steps[] = ['after', is_object($job) ? get_class($job) : $job, $data, $queue];
            })
            ->afterPushing(function ($job, $data, $queue) use (&$steps) {
                $steps[] = ['after again', is_object($job) ? get_class($job) : $job, $data, $queue];
            });

        $fake->push($job, ['foo' => 'bar'], 'redis');

        $this->assertSame([
            ['before', JobStub::class, ['foo' => 'bar'], 'redis'],
            ['before again', JobStub::class, ['foo' => 'bar'], 'redis'],
            ['after', JobStub::class, ['foo' => 'bar'], 'redis'],
            ['after again', JobStub::class, ['foo' => 'bar'], 'redis'],
        ], $steps);
    }

    public function testItCanFakePushedJobsWithClassAndPayload(): void
    {
        $fake = new QueueFake(new Application, ['JobStub']);

        $this->assertTrue($fake->shouldFakeJob('JobStub'));

        $fake->push('JobStub', ['job' => 'payload']);

        $fake->assertPushed('JobStub');
        $fake->assertPushed('JobStub', 1);
        $fake->assertPushed('JobStub', fn ($job, $queue, $payload) => $payload === ['job' => 'payload']);
    }

    public function testAssertChainUsingClassesOrObjectsArray(): void
    {
        $job = new JobWithChainStub([
            new JobStub,
        ]);

        $job->assertHasChain([
            JobStub::class,
        ]);

        $job->assertHasChain([
            new JobStub,
        ]);
    }

    public function testAssertNoChain(): void
    {
        $job = new JobWithChainStub([]);

        $job->assertDoesntHaveChain();
    }

    public function testAssertChainErrorHandling(): void
    {
        $job = new JobWithChainStub([
            new JobStub,
        ]);

        try {
            $job->assertHasChain([]);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The expected chain can not be empty.', $e->getMessage());
        }

        try {
            $job->assertHasChain([
                new JobStub,
                new JobStub,
            ]);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The job does not have the expected chain.', $e->getMessage());
        }

        try {
            $job->assertHasChain([
                JobStub::class,
                JobStub::class,
            ]);
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The job does not have the expected chain.', $e->getMessage());
        }

        try {
            $job->assertDoesntHaveChain();
            $this->fail();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('The job has chained jobs.', $e->getMessage());
        }
    }

    public function testPendingJobs(): void
    {
        $this->fake->push($this->job, '', 'foo');
        $this->fake->push(new JobToFakeStub, '', 'bar');

        $pending = $this->fake->pendingJobs('foo');

        $this->assertCount(1, $pending);
        $this->assertInstanceOf(InspectedJob::class, $pending->first());
        $this->assertSame(JobStub::class, $pending->first()->name);
        $this->assertSame(0, $pending->first()->attempts);
        $this->assertSame('foo', $pending->first()->queue);
    }

    public function testPendingJobsAcceptsUnitEnums(): void
    {
        $this->fake->push($this->job, '', QueueNameEnumStub::Foo);
        $this->fake->push(new JobToFakeStub, '', QueueNameEnumStub::Bar);

        $pending = $this->fake->pendingJobs(QueueNameEnumStub::Foo);

        $this->assertCount(1, $pending);
        $this->assertSame(JobStub::class, $pending->first()->name);
    }

    public function testAllPendingJobs(): void
    {
        $this->fake->push($this->job, '', 'foo');
        $this->fake->push(new JobToFakeStub, '', 'bar');

        $pending = $this->fake->allPendingJobs();

        $this->assertCount(2, $pending);
        $this->assertInstanceOf(InspectedJob::class, $pending->first());
        $this->assertTrue($pending->contains(fn ($job) => $job->name === JobStub::class));
        $this->assertTrue($pending->contains(fn ($job) => $job->name === JobToFakeStub::class));
    }

    public function testTotalSize(): void
    {
        $this->fake->push($this->job, '', 'foo');
        $this->fake->later(10, new JobToFakeStub, '', 'bar');
        $this->fake->reserve(new JobToFakeStub, 'baz');

        $this->assertSame(3, $this->fake->totalSize());
    }

    public function testTotalPendingSize(): void
    {
        $this->fake->push($this->job, '', 'foo');
        $this->fake->push(new JobToFakeStub, '', 'bar');

        $this->assertSame(2, $this->fake->totalPendingSize());
    }

    public function testDelayedJobs(): void
    {
        $this->fake->later(10, $this->job, '', 'foo');
        $this->fake->later(10, new JobToFakeStub, '', 'bar');

        $delayed = $this->fake->delayedJobs('foo');

        $this->assertCount(1, $delayed);
        $this->assertInstanceOf(InspectedJob::class, $delayed->first());
        $this->assertSame(JobStub::class, $delayed->first()->name);
        $this->assertSame(0, $delayed->first()->attempts);
        $this->assertSame('foo', $delayed->first()->queue);
    }

    public function testAllDelayedJobs(): void
    {
        $this->fake->later(10, $this->job, '', 'foo');
        $this->fake->later(10, new JobToFakeStub, '', 'bar');

        $delayed = $this->fake->allDelayedJobs();

        $this->assertCount(2, $delayed);
        $this->assertInstanceOf(InspectedJob::class, $delayed->first());
        $this->assertTrue($delayed->contains(fn ($job) => $job->name === JobStub::class));
        $this->assertTrue($delayed->contains(fn ($job) => $job->name === JobToFakeStub::class));
    }

    public function testTotalDelayedSize(): void
    {
        $this->fake->later(10, $this->job, '', 'foo');
        $this->fake->later(10, new JobToFakeStub, '', 'bar');

        $this->assertSame(2, $this->fake->totalDelayedSize());
    }

    public function testDelayedSize(): void
    {
        $this->fake->later(10, $this->job, '', 'foo');
        $this->fake->later(10, new JobToFakeStub, '', 'bar');

        $this->assertSame(1, $this->fake->delayedSize('foo'));
        $this->assertSame(1, $this->fake->delayedSize('bar'));
        $this->assertSame(0, $this->fake->delayedSize('baz'));
    }

    public function testDelayedJobsAreStillPushed(): void
    {
        $this->fake->later(10, $this->job, '', 'foo');

        $this->fake->assertPushedOn('foo', JobStub::class);
    }

    public function testPendingDelayedAndReservedJobsAreDisjoint(): void
    {
        $this->fake->push($this->job, '', 'foo');
        $this->fake->later(0, new JobToFakeStub, '', 'foo');
        $this->fake->reserve(new JobWithSerialization('reserved'), 'foo');

        $this->assertSame(1, $this->fake->pendingSize('foo'));
        $this->assertSame(1, $this->fake->delayedSize('foo'));
        $this->assertSame(1, $this->fake->reservedSize('foo'));
        $this->assertSame(3, $this->fake->size('foo'));
        $this->assertSame(1, $this->fake->totalPendingSize());
        $this->assertSame(1, $this->fake->totalDelayedSize());
        $this->assertSame(1, $this->fake->totalReservedSize());
        $this->assertSame(3, $this->fake->totalSize());
        $this->fake->assertCount(2);
    }

    public function testCreationTimeOfOldestPendingJob(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $this->assertNull($this->fake->creationTimeOfOldestPendingJob('foo'));

        $this->fake->push($this->job, '', 'foo');

        CarbonImmutable::setTestNow($now->addMinutes(5));

        $this->fake->push(new JobToFakeStub, '', 'foo');

        $this->assertSame($now->getTimestamp(), $this->fake->creationTimeOfOldestPendingJob('foo'));
    }

    public function testDelayedJobsDoNotDetermineTheOldestPendingTime(): void
    {
        CarbonImmutable::setTestNow($now = CarbonImmutable::now());

        $this->fake->later(0, $this->job, '', 'foo');

        CarbonImmutable::setTestNow($pendingAt = $now->addMinutes(5));

        $this->fake->push(new JobToFakeStub, '', 'foo');

        $this->assertSame(
            $pendingAt->getTimestamp(),
            $this->fake->creationTimeOfOldestPendingJob('foo'),
        );
    }

    public function testReservedJobs(): void
    {
        $this->fake->reserve($this->job, 'foo');
        $this->fake->reserve(new JobToFakeStub, 'bar');

        $reserved = $this->fake->reservedJobs('foo');

        $this->assertCount(1, $reserved);
        $this->assertInstanceOf(InspectedJob::class, $reserved->first());
        $this->assertSame(JobStub::class, $reserved->first()->name);
        $this->assertSame(0, $reserved->first()->attempts);
        $this->assertSame('foo', $reserved->first()->queue);
    }

    public function testAllReservedJobs(): void
    {
        $this->fake->reserve($this->job, 'foo');
        $this->fake->reserve(new JobToFakeStub, 'bar');

        $reserved = $this->fake->allReservedJobs();

        $this->assertCount(2, $reserved);
        $this->assertInstanceOf(InspectedJob::class, $reserved->first());
        $this->assertTrue($reserved->contains(fn ($job) => $job->name === JobStub::class));
        $this->assertTrue($reserved->contains(fn ($job) => $job->name === JobToFakeStub::class));
    }

    public function testTotalReservedSize(): void
    {
        $this->fake->reserve($this->job, 'foo');
        $this->fake->reserve(new JobToFakeStub, 'bar');

        $this->assertSame(2, $this->fake->totalReservedSize());
    }

    public function testReservedSize(): void
    {
        $this->fake->reserve($this->job, 'foo');
        $this->fake->reserve(new JobToFakeStub, 'bar');

        $this->assertSame(1, $this->fake->reservedSize('foo'));
        $this->assertSame(1, $this->fake->reservedSize('bar'));
        $this->assertSame(0, $this->fake->reservedSize('baz'));
    }

    public function testReservedJobsAreNotPushed(): void
    {
        $this->fake->reserve($this->job, 'foo');

        $this->fake->assertNotPushed(JobStub::class);
    }

    public function testClearReserved(): void
    {
        $this->fake->reserve($this->job, 'foo');
        $this->fake->reserve(new JobToFakeStub, 'bar');

        $this->fake->clearReserved();

        $this->assertSame(0, $this->fake->reservedSize('foo'));
        $this->assertSame(0, $this->fake->reservedSize('bar'));
    }

    public function testGetRawPushes(): void
    {
        $this->fake->pushRaw('some-payload', null, ['options' => 'yeah']);
        $this->fake->pushRaw('some-other-payload', 'my-queue', ['options' => 'also yeah']);

        $actualPushedRaw = $this->fake->rawPushes();

        $this->assertEqualsCanonicalizing([
            ['payload' => 'some-payload', 'queue' => null, 'options' => ['options' => 'yeah']],
            ['payload' => 'some-other-payload', 'queue' => 'my-queue', 'options' => ['options' => 'also yeah']],
        ], $actualPushedRaw);
    }

    public function testRawPushesAcceptUnitEnums(): void
    {
        $this->fake->pushRaw('some-payload', QueueNameEnumStub::Foo, ['options' => 'yeah']);

        $this->assertEqualsCanonicalizing([
            ['payload' => 'some-payload', 'queue' => 'foo', 'options' => ['options' => 'yeah']],
        ], $this->fake->rawPushes());

        $pushedRaw = $this->fake->pushedRaw(
            fn ($payload, $queue, $options) => $payload === 'some-payload'
                && $queue === 'foo'
                && $options['options'] === 'yeah'
        );

        $this->assertCount(1, $pushedRaw);
    }

    public function testPushedRaw(): void
    {
        $this->fake->pushRaw('some-payload', null, ['options' => 'yeah']);
        $this->fake->pushRaw('some-other-payload', 'my-queue', ['options' => 'also yeah']);

        $this->assertCount(2, $this->fake->pushedRaw());

        $pushedRaw = $this->fake->pushedRaw(fn ($payload) => $payload === 'some-payload');
        $this->assertCount(1, $pushedRaw);
        $this->assertEqualsCanonicalizing(
            ['payload' => 'some-payload', 'queue' => null, 'options' => ['options' => 'yeah']],
            $pushedRaw[0]
        );

        $pushedRaw = $this->fake->pushedRaw(
            fn ($payload, $queue, $options) => $payload === 'some-other-payload'
                && $queue === 'my-queue'
                && $options['options'] === 'also yeah'
        );
        $this->assertCount(1, $pushedRaw);

        $pushedRaw = $this->fake->pushedRaw(fn ($payload, $queue, $options) => $options === []);
        $this->assertCount(0, $pushedRaw);
    }

    public function testPartialFakePreservesDelayOnTheDefaultConnection(): void
    {
        $job = new JobStub;
        $queue = m::mock(Queue::class);
        $queue->shouldReceive('later')
            ->once()
            ->with(10, $job, ['key' => 'value'], 'emails')
            ->andReturn('job-id');
        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->once()->with(null)->andReturn($queue);

        $fake = (new QueueFake(new Application, [], $manager))->except(JobStub::class);

        $this->assertSame('job-id', $fake->later(10, $job, ['key' => 'value'], 'emails'));
        $fake->assertNotPushed(JobStub::class);
    }

    public function testPartialFakeUsesTheJobConnectionAndLaterOnPreservesDelay(): void
    {
        $job = new JobWithConnectionStub;
        $queue = m::mock(Queue::class);
        $queue->shouldReceive('later')
            ->once()
            ->with(15, $job, '', 'emails')
            ->andReturn('job-id');
        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->once()->with('redis')->andReturn($queue);

        $fake = (new QueueFake(new Application, [], $manager))->except(JobWithConnectionStub::class);

        $this->assertSame('job-id', $fake->laterOn('emails', 15, $job));
        $fake->assertNotPushed(JobWithConnectionStub::class);
    }

    public function testBulkClassifiesPlainAndDelayedJobsWithoutDuplicatingHistory(): void
    {
        $delayedJob = (new JobWithConnectionStub)->delay(10);

        $this->fake->bulk([$this->job, $delayedJob], queue: 'emails');

        $this->assertSame(1, $this->fake->pendingSize('emails'));
        $this->assertSame(1, $this->fake->delayedSize('emails'));
        $this->assertSame(2, $this->fake->size('emails'));
        $this->fake->assertCount(2);
    }

    public function testBeforeCallbackFailurePreventsPublicationAndAfterCallbacks(): void
    {
        $afterCalled = false;
        $this->fake->beforePushing(fn () => throw new RuntimeException('before failed'));
        $this->fake->afterPushing(function () use (&$afterCalled): void {
            $afterCalled = true;
        });

        try {
            $this->fake->later(10, $this->job);
            $this->fail('Expected the before callback failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('before failed', $exception->getMessage());
        }

        $this->fake->assertNothingPushed();
        $this->assertFalse($afterCalled);
    }

    public function testPassThroughFailureDoesNotRunAfterCallbacks(): void
    {
        $job = new JobStub;
        $queue = m::mock(Queue::class);
        $queue->shouldReceive('push')->once()->andThrow(new RuntimeException('push failed'));
        $manager = m::mock(QueueManager::class);
        $manager->shouldReceive('connection')->once()->with(null)->andReturn($queue);
        $afterCalled = false;

        $fake = (new QueueFake(new Application, [], $manager))
            ->except(JobStub::class)
            ->afterPushing(function () use (&$afterCalled): void {
                $afterCalled = true;
            });

        try {
            $fake->push($job);
            $this->fail('Expected the queue failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('push failed', $exception->getMessage());
        }

        $this->assertFalse($afterCalled);
    }

    public function testAfterCallbackFailurePropagatesAfterFakePublication(): void
    {
        $this->fake->afterPushing(fn () => throw new RuntimeException('after failed'));

        try {
            $this->fake->push($this->job);
            $this->fail('Expected the after callback failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('after failed', $exception->getMessage());
        }

        $this->fake->assertPushed(JobStub::class);
    }
}

enum QueueNameEnumStub: string
{
    case Foo = 'foo';
    case Bar = 'bar';
}

class JobStub
{
    public function handle(): void
    {
    }
}

class JobToFakeStub
{
    public function handle(): void
    {
    }
}

#[Delay(15)]
class JobWithDelayAttributeStub
{
    use Queueable;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }
}

class JobWithRuntimeDelayStub
{
    use Queueable;

    /**
     * Handle the job.
     */
    public function handle(): void
    {
    }
}

class JobWithConnectionStub
{
    use Queueable;

    public function __construct()
    {
        $this->connection = 'redis';
    }

    public function handle(): void
    {
    }
}

class JobWithChainStub
{
    use Queueable;

    public function __construct(array $chain)
    {
        $this->chain($chain);
    }

    public function handle(): void
    {
    }
}

class JobWithChainAndParameterStub
{
    use Queueable;

    public string $parameter;

    public function __construct(string $parameter, array $chain)
    {
        $this->parameter = $parameter;
        $this->chain($chain);
    }

    public function handle(): void
    {
    }
}

class JobWithSerialization
{
    use Queueable;

    public function __construct(public string $value)
    {
    }

    public function __serialize(): array
    {
        return ['value' => $this->value . '-serialized'];
    }

    public function __unserialize(array $data): void
    {
        $this->value = $data['value'] . '-unserialized';
    }
}

class QueueFakeUniqueJobStub implements ShouldBeUnique
{
    use Queueable;
}
