<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Generator;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Foundation\Application;
use Hypervel\Queue\Console\RetryCommand;
use Hypervel\Queue\Events\JobRetryRequested;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\SqsQueue;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class QueueRetryCommandTest extends TestCase
{
    public function testRetriesSingleJobByPushingItsRawPayload(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $job = $this->failedJob(id: '5', connection: 'database', queue: 'default');

        $failer->shouldReceive('find')->once()->with('5')->andReturn($job);
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '5'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('5');

        $this->runRetryCommand(['id' => ['5']], $failer, ['database' => $queue]);
    }

    public function testRetriesSingleJobByPushingItsRawPayloadWithOptions(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(SqsQueue::class);

        $job = $this->failedJob(id: '5', connection: 'database', queue: 'default');

        $failer->shouldReceive('find')->once()->with('5')->andReturn($job);
        $queue->shouldReceive('getQueueableOptions')
            ->once()
            ->with(m::type(QueueRetryCommandTestJob::class), 'default', $job->payload)
            ->andReturn(['MySpecialOption' => 'option-1']);
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '5'), 'default', ['MySpecialOption' => 'option-1']);
        $failer->shouldReceive('forget')->once()->with('5');

        $this->runRetryCommand(['id' => ['5']], $failer, ['database' => $queue]);
    }

    public function testDisplaysErrorWhenJobIsNotFound(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('find')->once()->with('123')->andReturn(null);

        $output = $this->runRetryCommand(['id' => ['123']], $failer, []);

        $this->assertStringContainsString('Unable to find failed job with ID [123].', $output);
    }

    #[TestWith([['1', '2']])]
    #[TestWith([[1, 2]])]
    public function testRetriesAllFailedJobsUsingTheProvidersIds(array $ids): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $failer->shouldReceive('ids')->once()->withNoArgs()->andReturn($ids);

        $failer->shouldReceive('find')->once()->with('1')->andReturn($this->failedJob(id: '1', connection: 'database', queue: 'default'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '1'), 'default', []);
        $failer->shouldReceive('forget')->once()->with(m::on(fn (mixed $id): bool => $id === 1));

        $failer->shouldReceive('find')->once()->with('2')->andReturn($this->failedJob(id: '2', connection: 'database', queue: 'emails'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '2'), 'emails', []);
        $failer->shouldReceive('forget')->once()->with('2');

        $output = $this->runRetryCommand(['id' => ['all']], $failer, ['database' => $queue]);

        $this->assertStringContainsString('Pushing failed queue jobs back onto the queue.', $output);
        $this->assertStringContainsString('DONE', $output);
    }

    public function testRetriesJobsOnTheSpecifiedQueue(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $failer->shouldReceive('ids')->once()->with('emails')->andReturn(['2']);
        $failer->shouldReceive('find')->once()->with('2')->andReturn($this->failedJob(id: '2', connection: 'database', queue: 'emails'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '2'), 'emails', []);
        $failer->shouldReceive('forget')->once()->with('2');

        $this->runRetryCommand(['--queue' => 'emails'], $failer, ['database' => $queue]);
    }

    public function testDisplaysErrorWhenTheSpecifiedQueueHasNoFailedJobs(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('ids')->once()->with('emails')->andReturn([]);

        $output = $this->runRetryCommand(['--queue' => 'emails'], $failer, []);

        $this->assertStringContainsString('Unable to find failed jobs for queue [emails].', $output);
        $this->assertStringContainsString('No retryable jobs found.', $output);
    }

    public function testRetriesJobsWithinTheGivenIdRange(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $failer->shouldReceive('find')->once()->with(1)->andReturn($this->failedJob(id: '1', connection: 'database', queue: 'default'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '1'), 'default', []);
        $failer->shouldReceive('forget')->once()->with(1);

        $failer->shouldReceive('find')->once()->with(2)->andReturn($this->failedJob(id: '2', connection: 'database', queue: 'default'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '2'), 'default', []);
        $failer->shouldReceive('forget')->once()->with(2);

        $failer->shouldReceive('find')->once()->with(3)->andReturn($this->failedJob(id: '3', connection: 'database', queue: 'default'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '3'), 'default', []);
        $failer->shouldReceive('forget')->once()->with(3);

        $this->runRetryCommand(['--range' => ['1-3']], $failer, ['database' => $queue]);
    }

    public function testDisplaysInfoWhenThereAreNoJobsToRetry(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);

        $output = $this->runRetryCommand(['id' => []], $failer, []);

        $this->assertStringContainsString('No retryable jobs found.', $output);
    }

    public function testItResetsAttemptsCountWhenRetryingAJob(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $job = $this->failedJob(id: '1', connection: 'database', queue: 'default', payload: ['attempts' => 5]);

        $failer->shouldReceive('find')->once()->with('1')->andReturn($job);
        $queue->shouldReceive('pushRaw')->once()->with(m::on(function (string $payload): bool {
            return json_decode($payload, true)['attempts'] === 0;
        }), 'default', []);
        $failer->shouldReceive('forget')->once()->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['database' => $queue]);
    }

    public function testRefreshesTheRetryUntilTimestampWhenTheJobDefinesRetryUntil(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $job = $this->failedJob(
            id: '1',
            connection: 'database',
            queue: 'default',
            payload: ['retryUntil' => 0],
            job: new QueueRetryCommandTestJobWithRetryUntil(retryUntil: 1234567890)
        );

        $failer->shouldReceive('find')->once()->with('1')->andReturn($job);
        $queue->shouldReceive('pushRaw')->once()->with(m::on(function (string $payload): bool {
            return json_decode($payload, true)['retryUntil'] === 1234567890;
        }), 'default', []);
        $failer->shouldReceive('forget')->once()->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['database' => $queue]);
    }

    public function testPassesQueueableOptionsToTheQueueWhenRetryingASingleJob(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(SqsQueue::class);

        $job = $this->failedJob(id: '1', connection: 'sqs', queue: 'default');

        $failer->shouldReceive('find')->once()->with('1')->andReturn($job);
        $queue->shouldReceive('getQueueableOptions')
            ->once()
            ->with(m::type(QueueRetryCommandTestJob::class), 'default', $job->payload)
            ->andReturn(['MySpecialOption' => 'option-1']);
        $queue->shouldReceive('pushRaw')->once()->with(m::type('string'), 'default', ['MySpecialOption' => 'option-1']);
        $failer->shouldReceive('forget')->once()->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['sqs' => $queue]);
    }

    public function testDispatchesRetryRequestedEventWhenRetryingASingleJob(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);
        $events = m::mock(Dispatcher::class);

        $job = $this->failedJob(id: '1', connection: 'database', queue: 'default');

        $failer->shouldReceive('find')->once()->with('1')->andReturn($job);
        $events->shouldReceive('dispatch')->once()->with(m::type(JobRetryRequested::class));
        $queue->shouldReceive('pushRaw')->once();
        $failer->shouldReceive('forget')->once()->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['database' => $queue], $events);
    }

    public function testRetriesCollectionOfJobs(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('job-1');

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-2'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('job-2');

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);
    }

    public function testRetriesALazyCollectionOfJobsAndDoesNotResolveThemAllEagerly(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $unresolvedCounts = [];

        $pendingJobs = [
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
            'job-3' => $this->failedJob(id: 'job-3', connection: 'database', queue: 'default'),
        ];

        $supplierStarts = 0;
        $jobs = new LazyCollection(function () use (&$pendingJobs, &$supplierStarts): Generator {
            ++$supplierStarts;

            while ($job = array_shift($pendingJobs)) {
                yield $job->id => $job;
            }
        });

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('job-1')->andReturnUsing(function () use (&$unresolvedCounts, &$pendingJobs): bool {
            $unresolvedCounts[] = count($pendingJobs);

            return true;
        });

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-2'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('job-2')->andReturnUsing(function () use (&$unresolvedCounts, &$pendingJobs): bool {
            $unresolvedCounts[] = count($pendingJobs);

            return true;
        });

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-3'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('job-3')->andReturnUsing(function () use (&$unresolvedCounts, &$pendingJobs): bool {
            $unresolvedCounts[] = count($pendingJobs);

            return true;
        });

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);

        $this->assertSame(1, $supplierStarts);
        $this->assertCount(3, $unresolvedCounts);
        $this->assertSame(2, $unresolvedCounts[0]);
        $this->assertSame(1, $unresolvedCounts[1]);
        $this->assertSame(0, $unresolvedCounts[2]);
    }

    public function testItResetsAttemptsCountWhenRetryingACollectionOfJobs(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default', payload: ['attempts' => 5]),
        ]);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);
        $queue->shouldReceive('pushRaw')->once()->with(m::on(function (string $payload): bool {
            return json_decode($payload, true)['attempts'] === 0;
        }), 'default', []);
        $failer->shouldReceive('forget')->once()->with('job-1');

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);
    }

    public function testRefreshesTheRetryUntilTimestampWhenRetryingACollectionOfJobs(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $jobs = new Collection([
            'job-1' => $this->failedJob(
                id: 'job-1',
                connection: 'database',
                queue: 'default',
                payload: ['retryUntil' => 0],
                job: new QueueRetryCommandTestJobWithRetryUntil(retryUntil: 1234567890)
            ),
        ]);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);
        $queue->shouldReceive('pushRaw')->once()->with(m::on(function (string $payload): bool {
            return json_decode($payload, true)['retryUntil'] === 1234567890;
        }), 'default', []);
        $failer->shouldReceive('forget')->once()->with('job-1');

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);
    }

    public function testDisplaysErrorWhenTheGivenIdResolvesToAnEmptyCollection(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $failer->shouldReceive('find')->once()->with('batch')->andReturn(new Collection);

        $output = $this->runRetryCommand(['id' => ['batch']], $failer, []);

        $this->assertStringContainsString('Pushing failed queue jobs back onto the queue.', $output);
        $this->assertStringContainsString('Unable to find any failed jobs with ID [batch].', $output);
        $this->assertStringNotContainsString('No retryable jobs found.', $output);
    }

    public function testDisplaysErrorWhenTheGivenIdResolvesToAnEmptyLazyCollection(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);

        $resolved = false;

        $jobs = new LazyCollection(function () use (&$resolved): Generator {
            $resolved = true;

            yield from [];
        });

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);

        $output = $this->runRetryCommand(['id' => ['batch']], $failer, []);

        $this->assertTrue($resolved);
        $this->assertStringContainsString('Unable to find any failed jobs with ID [batch].', $output);
        $this->assertStringNotContainsString('No retryable jobs found.', $output);
    }

    public function testContinuesRetryingRemainingJobsAfterAJobIsNotFound(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $failer->shouldReceive('find')->once()->with('1')->andReturn(null);

        $failer->shouldReceive('find')->once()->with('2')->andReturn($this->failedJob(id: '2', connection: 'database', queue: 'default'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '2'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('2');

        $output = $this->runRetryCommand(['id' => ['1', '2']], $failer, ['database' => $queue]);

        $this->assertStringContainsString('Unable to find failed job with ID [1].', $output);
        $this->assertStringContainsString('DONE', $output);
    }

    public function testContinuesRetryingRemainingJobsAfterAnIdResolvesToAnEmptyCollection(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn(new Collection);

        $failer->shouldReceive('find')->once()->with('2')->andReturn($this->failedJob(id: '2', connection: 'database', queue: 'default'));
        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '2'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('2');

        $output = $this->runRetryCommand(['id' => ['batch', '2']], $failer, ['database' => $queue]);

        $this->assertStringContainsString('Unable to find any failed jobs with ID [batch].', $output);
        $this->assertStringContainsString('DONE', $output);
    }

    public function testRetriesAMixtureOfSingleJobsAndCollectionsOfJobs(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn(new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]));

        $failer->shouldReceive('find')->once()->with('9')->andReturn($this->failedJob(id: '9', connection: 'database', queue: 'default'));

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('job-1');

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-2'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('job-2');

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: '9'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('9');

        $this->runRetryCommand(['id' => ['batch', '9']], $failer, ['database' => $queue]);
    }

    public function testForgetsJobsUsingTheCollectionKeyRatherThanTheJobId(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $key = 'https://cloud.test/failed-jobs/batch-1:job-1';

        $failer->shouldReceive('find')->once()->with('batch')->andReturn(new Collection([
            $key => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
        ]));

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->shouldReceive('forget')->once()->with($key);

        $output = $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);

        $this->assertMatchesRegularExpression('/^  ' . preg_quote($key, '/') . ' \.+/m', $output);
    }

    public function testForgetsJobsUsingTheCollectionKeyWhenTheCollectionIsNotKeyed(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn(new Collection([
            $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]));

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->shouldReceive('forget')->once()->with(0);

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-2'), 'default', []);
        $failer->shouldReceive('forget')->once()->with(1);

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);
    }

    public function testDispatchesRetryRequestedEventForEveryJobInACollection(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);
        $events = m::mock(Dispatcher::class);

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);

        $dispatched = [];

        $events->shouldReceive('dispatch')->twice()->with(m::type(JobRetryRequested::class))->andReturnUsing(function (JobRetryRequested $event) use (&$dispatched): void {
            $dispatched[] = $event->job->id;
        });

        $queue->shouldReceive('pushRaw')->twice();
        $failer->shouldReceive('forget')->once()->with('job-1');
        $failer->shouldReceive('forget')->once()->with('job-2');

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue], $events);

        $this->assertSame(['job-1', 'job-2'], $dispatched);
    }

    public function testStopsRetryingWhenAJobInACollectionFailsToBePushed(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $pendingJobs = [
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
            'job-3' => $this->failedJob(id: 'job-3', connection: 'database', queue: 'default'),
        ];

        $yielded = 0;
        $iterator = value(function () use (&$pendingJobs, &$yielded): Generator {
            while ($job = array_shift($pendingJobs)) {
                ++$yielded;
                yield $job->id => $job;
            }
        });

        $jobs = new LazyCollection(fn (): Generator => yield from $iterator);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('job-1');

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-2'), 'default', [])->andThrow(new RuntimeException('Unable to push job.'));
        $failer->shouldNotReceive('forget')->with('job-2');

        $queue->shouldNotReceive('pushRaw')->with($this->retriedPayload(id: 'job-3'), 'default', []);
        $failer->shouldNotReceive('forget')->with('job-3');

        try {
            $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);

            $this->fail('The exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Unable to push job.', $e->getMessage());
        }

        $this->assertSame(['job-3'], array_keys($pendingJobs));
    }

    public function testDispatchesTheEventBeforePushingAndForgetsTheJobAfterwards(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);
        $events = m::mock(Dispatcher::class);

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
        ]);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);

        $sequence = [];

        $events->shouldReceive('dispatch')->once()->with(m::type(JobRetryRequested::class))->andReturnUsing(function () use (&$sequence): void {
            $sequence[] = 'dispatch';
        });

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-1'), 'default', [])->andReturnUsing(function () use (&$sequence): void {
            $sequence[] = 'push';
        });

        $failer->shouldReceive('forget')->once()->with('job-1')->andReturnUsing(function () use (&$sequence): bool {
            $sequence[] = 'forget';

            return true;
        });

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue], $events);

        $this->assertSame(['dispatch', 'push', 'forget'], $sequence);
    }

    public function testOutputsAnEntryForEveryJobInACollection(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]);

        $failer->shouldReceive('find')->once()->with('batch')->andReturn($jobs);

        $queue->shouldReceive('pushRaw')->twice();
        $failer->shouldReceive('forget')->once()->with('job-1');
        $failer->shouldReceive('forget')->once()->with('job-2');

        $output = $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);

        $this->assertSame(1, substr_count($output, 'Pushing failed queue jobs back onto the queue.'));
        $this->assertSame(1, substr_count($output, 'job-1'));
        $this->assertSame(1, substr_count($output, 'job-2'));
        $this->assertSame(2, substr_count($output, 'DONE'));
    }

    public function testRetriesCollectionsOfJobsWhenRetryingAllFailedJobs(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $failer->shouldReceive('ids')->once()->withNoArgs()->andReturn(['batch-1', 'batch-2']);

        $failer->shouldReceive('find')->once()->with('batch-1')->andReturn(new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
        ]));

        $failer->shouldReceive('find')->once()->with('batch-2')->andReturn(new Collection([
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'emails'),
        ]));

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->shouldReceive('forget')->once()->with('job-1');

        $queue->shouldReceive('pushRaw')->once()->with($this->retriedPayload(id: 'job-2'), 'emails', []);
        $failer->shouldReceive('forget')->once()->with('job-2');

        $this->runRetryCommand(['id' => ['all']], $failer, ['database' => $queue]);
    }

    public function testRetriesTheSameJobTwiceWhenItAppearsInTwoCollections(): void
    {
        $failer = m::mock(FailedJobProviderInterface::class);
        $queue = m::mock(QueueContract::class);

        $failer->shouldReceive('find')->once()->with('batch-1')->andReturn(new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
        ]));

        $failer->shouldReceive('find')->once()->with('batch-2')->andReturn(new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
        ]));

        $queue->shouldReceive('pushRaw')->twice()->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->shouldReceive('forget')->twice()->with('job-1');

        $this->runRetryCommand(['id' => ['batch-1', 'batch-2']], $failer, ['database' => $queue]);
    }

    /**
     * Create a failed job record.
     */
    private function failedJob(
        string $id,
        string $connection,
        string $queue,
        array $payload = [],
        object $job = new QueueRetryCommandTestJob,
    ): stdClass {
        return (object) [
            'id' => $id,
            'connection' => $connection,
            'queue' => $queue,
            'payload' => json_encode([
                'uuid' => $id,
                'displayName' => get_class($job),
                'data' => [
                    'commandName' => get_class($job),
                    'command' => serialize($job),
                ],
                ...$payload,
            ]),
        ];
    }

    /**
     * Build the expected payload for a retried job.
     */
    private function retriedPayload(string $id, object $job = new QueueRetryCommandTestJob): string
    {
        return json_encode([
            'uuid' => $id,
            'displayName' => QueueRetryCommandTestJob::class,
            'data' => [
                'commandName' => get_class($job),
                'command' => serialize($job),
            ],
        ]);
    }

    /**
     * Run the retry command with the given provider and connections.
     */
    private function runRetryCommand(array $input, FailedJobProviderInterface $failer, array $connections, ?Dispatcher $events = null): string
    {
        $container = new Application;

        $container->instance('queue.failer', $failer);

        $manager = m::mock(QueueManager::class);

        foreach ($connections as $name => $queue) {
            $manager->shouldReceive('connection')->with($name)->andReturn($queue);
        }

        $container->instance('queue', $manager);

        if (is_null($events)) {
            $events = m::mock(Dispatcher::class);
            $events->shouldReceive('dispatch');
        }

        $events->shouldReceive('hasListeners')->andReturnFalse()->byDefault();
        $events->shouldReceive('hasListeners')->with(JobRetryRequested::class)->andReturnTrue();

        $container->instance('events', $events);

        $command = new RetryCommand;
        $command->setHypervel($container);

        $output = new BufferedOutput;
        $command->run(new ArrayInput($input), $output);

        return $output->fetch();
    }
}

class QueueRetryCommandTestJob
{
}

class QueueRetryCommandTestJobWithRetryUntil
{
    /**
     * Create a job with a retry deadline.
     */
    public function __construct(private int $retryUntil = 0)
    {
    }

    /**
     * Return the retry deadline.
     */
    public function retryUntil(): int
    {
        return $this->retryUntil;
    }
}
