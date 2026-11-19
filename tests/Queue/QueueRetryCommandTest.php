<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Generator;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Events\CallQueuedListener;
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Foundation\Application;
use Hypervel\Queue\Attributes\RetryFor;
use Hypervel\Queue\Console\RetryCommand;
use Hypervel\Queue\Events\JobRetryRequested;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Hypervel\Queue\Failed\NullFailedJobProvider;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\SqsQueue;
use Hypervel\Queue\SyncQueue;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class QueueRetryCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testRetriesSingleJobByPushingItsRawPayload(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $job = $this->failedJob(id: '5', connection: 'database', queue: 'default');

        $failer->expects('find')->with('5')->returns($job);
        $queue->expects('pushRaw')->with($this->retriedPayload(id: '5'), 'default', []);
        $failer->expects('forget')->with(Argument::same('5'));

        $this->runRetryCommand(['id' => ['5']], $failer, ['database' => $queue]);
    }

    public function testRetriesSingleJobByPushingItsRawPayloadWithOptions(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(SqsQueue::class)->strict();

        $job = $this->failedJob(id: '5', connection: 'database', queue: 'default');

        $failer->expects('find')->with('5')->returns($job);
        $queue->expects('getQueueableOptions')
            ->with(Argument::type(QueueRetryCommandTestJob::class), 'default', $job->payload)
            ->returns(['MySpecialOption' => 'option-1']);
        $queue->expects('pushRaw')->with($this->retriedPayload(id: '5'), 'default', ['MySpecialOption' => 'option-1']);
        $failer->expects('forget')->with('5');

        $this->runRetryCommand(['id' => ['5']], $failer, ['database' => $queue]);
    }

    #[TestWith(['123'])]
    #[TestWith(['0'])]
    public function testDisplaysErrorWhenJobIsNotFound(string $id): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $failer->expects('find')->with($id)->returns(null);

        $output = $this->runRetryCommand(['id' => [$id]], $failer, []);

        $this->assertStringContainsString("Unable to find failed job with ID [{$id}].", $output);
    }

    #[TestWith([['1', '2']])]
    #[TestWith([[1, 2]])]
    public function testRetriesAllFailedJobsUsingTheProvidersIds(array $ids): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $failer->expects('ids')->with()->returns($ids);

        $failer->expects('find')->with(Argument::same($ids[0]))->returns($this->failedJob(id: '1', connection: 'database', queue: 'default'));
        $queue->expects('pushRaw')->with($this->retriedPayload(id: '1'), 'default', []);
        $failer->expects('forget')->with(Argument::same($ids[0]));

        $failer->expects('find')->with(Argument::same($ids[1]))->returns($this->failedJob(id: '2', connection: 'database', queue: 'emails'));
        $queue->expects('pushRaw')->with($this->retriedPayload(id: '2'), 'emails', []);
        $failer->expects('forget')->with(Argument::same($ids[1]));

        $output = $this->runRetryCommand(['id' => ['all']], $failer, ['database' => $queue]);

        $this->assertStringContainsString('Pushing failed queue jobs back onto the queue.', $output);
        $this->assertStringContainsString('DONE', $output);
    }

    #[TestWith(['emails'])]
    #[TestWith(['0'])]
    public function testRetriesJobsOnTheSpecifiedQueue(string $queueName): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $failer->expects('ids')->with($queueName)->returns(['2']);
        $failer->expects('find')->with('2')->returns($this->failedJob(id: '2', connection: 'database', queue: $queueName));
        $queue->expects('pushRaw')->with($this->retriedPayload(id: '2'), $queueName, []);
        $failer->expects('forget')->with('2');

        $this->runRetryCommand(['--queue' => $queueName], $failer, ['database' => $queue]);
    }

    public function testDisplaysErrorWhenTheSpecifiedQueueHasNoFailedJobs(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $failer->expects('ids')->with('emails')->returns([]);

        $output = $this->runRetryCommand(['--queue' => 'emails'], $failer, []);

        $this->assertStringContainsString('Unable to find failed jobs for queue [emails].', $output);
        $this->assertStringContainsString('No retryable jobs found.', $output);
    }

    public function testRetriesJobsWithinTheGivenIdRange(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $failer->expects('find')->with('1')->returns($this->failedJob(id: '1', connection: 'database', queue: 'default'));
        $queue->expects('pushRaw')->with($this->retriedPayload(id: '1'), 'default', []);
        $failer->expects('forget')->with('1');

        $failer->expects('find')->with('2')->returns($this->failedJob(id: '2', connection: 'database', queue: 'default'));
        $queue->expects('pushRaw')->with($this->retriedPayload(id: '2'), 'default', []);
        $failer->expects('forget')->with('2');

        $failer->expects('find')->with('3')->returns($this->failedJob(id: '3', connection: 'database', queue: 'default'));
        $queue->expects('pushRaw')->with($this->retriedPayload(id: '3'), 'default', []);
        $failer->expects('forget')->with('3');

        $this->runRetryCommand(['--range' => ['1-3']], $failer, ['database' => $queue]);
    }

    public function testDisplaysInfoWhenThereAreNoJobsToRetry(): void
    {
        $failer = new NullFailedJobProvider;

        $output = $this->runRetryCommand(['id' => []], $failer, []);

        $this->assertStringContainsString('No retryable jobs found.', $output);
    }

    public function testItResetsAttemptsCountWhenRetryingAJob(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $job = $this->failedJob(id: '1', connection: 'database', queue: 'default', payload: ['attempts' => 5]);

        $failer->expects('find')->with('1')->returns($job);
        $queue->expects('pushRaw')->with(Argument::satisfies(function (string $payload): bool {
            return json_decode($payload, true)['attempts'] === 0;
        }), 'default', []);
        $failer->expects('forget')->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['database' => $queue]);
    }

    #[TestWith([false], 'unmarked object payload')]
    #[TestWith([true], 'custom object handler')]
    public function testRefreshesTheRetryUntilTimestampWhenTheJobDefinesRetryUntil(bool $customHandler): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $job = $this->failedJob(
            id: '1',
            connection: 'database',
            queue: 'default',
            payload: ['retryUntil' => 0],
            job: new QueueRetryCommandTestJobWithRetryUntil(retryUntil: 1234567890)
        );

        if ($customHandler) {
            $producer = new QueueRetryCommandCustomQueue;
            $producer->setContainer(new Application);
            $payload = json_decode($producer->payloadFor(new QueueRetryCommandTestJobWithRetryUntil(1234567890)), true);
            $payload['retryUntil'] = 0;
            $job->payload = json_encode($payload);
        }

        $failer->expects('find')->with('1')->returns($job);
        $queue->expects('pushRaw')->with(Argument::satisfies(function (string $payload): bool {
            return json_decode($payload, true)['retryUntil'] === 1234567890;
        }), 'default', []);
        $failer->expects('forget')->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['database' => $queue]);
    }

    public function testRefreshesTheRetryUntilTimestampWhenTheJobDefinesRetryForAttribute(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');

        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $job = $this->failedJob(
            id: '1',
            connection: 'database',
            queue: 'default',
            payload: ['retryUntil' => 0],
            job: new QueueRetryCommandTestJobWithRetryForAttribute
        );

        $failer->expects('find')->with('1')->returns($job);
        $queue->expects('pushRaw')->with(Argument::satisfies(function (string $payload): bool {
            return json_decode($payload, true)['retryUntil'] === CarbonImmutable::now()->addSeconds(90)->getTimestamp();
        }), 'default', []);
        $failer->expects('forget')->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['database' => $queue]);
    }

    public function testKeepsTheRetryUntilPropertyOfAJobWithTheRetryForAttribute(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $job = $this->failedJob(
            id: '1',
            connection: 'database',
            queue: 'default',
            payload: ['retryUntil' => 1234567890],
            job: new QueueRetryCommandTestJobWithRetryForAttributeAndRetryUntilProperty
        );

        $failer->expects('find')->with('1')->returns($job);
        $queue->expects('pushRaw')->with(Argument::satisfies(function (string $payload): bool {
            return json_decode($payload, true)['retryUntil'] === 1234567890;
        }), 'default', []);
        $failer->expects('forget')->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['database' => $queue]);
    }

    #[TestWith([QueueRetryCommandTestListenerWithRetryForAttribute::class, 1767312090])]
    #[TestWith([QueueRetryCommandTestListenerWithRelativeRetryUntil::class, 1767312600])]
    #[TestWith([QueueRetryCommandTestListenerWithRetryUntil::class, 111])]
    #[TestWith([QueueRetryCommandTestListener::class, 111])]
    public function testRecalculatesTheRetryUntilTimestampOfAQueuedListener(string $listener, int $retryUntil): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');

        $listenerQueue = new QueueFake(new Container);
        $events = new EventDispatcher;
        $events->setQueueResolver(fn (): QueueFake => $listenerQueue);
        $events->listen('some.event', $listener . '@handle');
        $events->dispatch('some.event', [111]);

        CarbonImmutable::setTestNow('2026-01-02 00:00:00');

        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $job = $this->failedJob(
            id: '1',
            connection: 'database',
            queue: 'default',
            payload: ['retryUntil' => 111],
            job: $listenerQueue->pushed(CallQueuedListener::class)->sole()
        );

        $failer->expects('find')->with('1')->returns($job);
        $queue->expects('pushRaw')->with(Argument::satisfies(function (string $payload) use ($retryUntil): bool {
            return json_decode($payload, true)['retryUntil'] === $retryUntil;
        }), 'default', []);
        $failer->expects('forget')->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['database' => $queue]);
    }

    public function testPassesQueueableOptionsToTheQueueWhenRetryingASingleJob(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(SqsQueue::class)->strict();

        $job = $this->failedJob(id: '1', connection: 'sqs', queue: 'default');

        $failer->expects('find')->with('1')->returns($job);
        $queue->expects('getQueueableOptions')
            ->with(Argument::type(QueueRetryCommandTestJob::class), 'default', $job->payload)
            ->returns(['MySpecialOption' => 'option-1']);
        $queue->expects('pushRaw')->with(Argument::type('string'), 'default', ['MySpecialOption' => 'option-1']);
        $failer->expects('forget')->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['sqs' => $queue]);
    }

    public function testDispatchesRetryRequestedEventWhenRetryingASingleJob(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();
        $events = Double::for(Dispatcher::class)->strict();

        $job = $this->failedJob(id: '1', connection: 'database', queue: 'default');

        $failer->expects('find')->with('1')->returns($job);
        $events->expects('dispatch')->with(Argument::type(JobRetryRequested::class));
        $queue->expects('pushRaw');
        $failer->expects('forget')->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['database' => $queue], $events);
    }

    public function testRetriesCollectionOfJobs(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]);

        $failer->expects('find')->with('batch')->returns($jobs);

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->expects('forget')->with('job-1');

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-2'), 'default', []);
        $failer->expects('forget')->with('job-2');

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);
    }

    public function testRetriesALazyCollectionOfJobsAndDoesNotResolveThemAllEagerly(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

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

        $failer->expects('find')->with('batch')->returns($jobs);

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->expects('forget')->with('job-1')->resolves(function () use (&$unresolvedCounts, &$pendingJobs): bool {
            $unresolvedCounts[] = count($pendingJobs);

            return true;
        });

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-2'), 'default', []);
        $failer->expects('forget')->with('job-2')->resolves(function () use (&$unresolvedCounts, &$pendingJobs): bool {
            $unresolvedCounts[] = count($pendingJobs);

            return true;
        });

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-3'), 'default', []);
        $failer->expects('forget')->with('job-3')->resolves(function () use (&$unresolvedCounts, &$pendingJobs): bool {
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
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default', payload: ['attempts' => 5]),
        ]);

        $failer->expects('find')->with('batch')->returns($jobs);
        $queue->expects('pushRaw')->with(Argument::satisfies(function (string $payload): bool {
            return json_decode($payload, true)['attempts'] === 0;
        }), 'default', []);
        $failer->expects('forget')->with('job-1');

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);
    }

    public function testRefreshesTheRetryUntilTimestampWhenRetryingACollectionOfJobs(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $jobs = new Collection([
            'job-1' => $this->failedJob(
                id: 'job-1',
                connection: 'database',
                queue: 'default',
                payload: ['retryUntil' => 0],
                job: new QueueRetryCommandTestJobWithRetryUntil(retryUntil: 1234567890)
            ),
        ]);

        $failer->expects('find')->with('batch')->returns($jobs);
        $queue->expects('pushRaw')->with(Argument::satisfies(function (string $payload): bool {
            return json_decode($payload, true)['retryUntil'] === 1234567890;
        }), 'default', []);
        $failer->expects('forget')->with('job-1');

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);
    }

    public function testDisplaysErrorWhenTheGivenIdResolvesToAnEmptyCollection(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $failer->expects('find')->with('batch')->returns(new Collection);

        $output = $this->runRetryCommand(['id' => ['batch']], $failer, []);

        $this->assertStringContainsString('Pushing failed queue jobs back onto the queue.', $output);
        $this->assertStringContainsString('Unable to find any failed jobs with ID [batch].', $output);
        $this->assertStringNotContainsString('No retryable jobs found.', $output);
    }

    public function testDisplaysErrorWhenTheGivenIdResolvesToAnEmptyLazyCollection(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();

        $resolved = false;

        $jobs = new LazyCollection(function () use (&$resolved): Generator {
            $resolved = true;

            yield from [];
        });

        $failer->expects('find')->with('batch')->returns($jobs);

        $output = $this->runRetryCommand(['id' => ['batch']], $failer, []);

        $this->assertTrue($resolved);
        $this->assertStringContainsString('Unable to find any failed jobs with ID [batch].', $output);
        $this->assertStringNotContainsString('No retryable jobs found.', $output);
    }

    public function testContinuesRetryingRemainingJobsAfterAJobIsNotFound(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $failer->expects('find')->with('1')->returns(null);

        $failer->expects('find')->with('2')->returns($this->failedJob(id: '2', connection: 'database', queue: 'default'));
        $queue->expects('pushRaw')->with($this->retriedPayload(id: '2'), 'default', []);
        $failer->expects('forget')->with('2');

        $output = $this->runRetryCommand(['id' => ['1', '2']], $failer, ['database' => $queue]);

        $this->assertStringContainsString('Unable to find failed job with ID [1].', $output);
        $this->assertStringContainsString('DONE', $output);
    }

    public function testContinuesRetryingRemainingJobsAfterAnIdResolvesToAnEmptyCollection(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $failer->expects('find')->with('batch')->returns(new Collection);

        $failer->expects('find')->with('2')->returns($this->failedJob(id: '2', connection: 'database', queue: 'default'));
        $queue->expects('pushRaw')->with($this->retriedPayload(id: '2'), 'default', []);
        $failer->expects('forget')->with('2');

        $output = $this->runRetryCommand(['id' => ['batch', '2']], $failer, ['database' => $queue]);

        $this->assertStringContainsString('Unable to find any failed jobs with ID [batch].', $output);
        $this->assertStringContainsString('DONE', $output);
    }

    public function testRetriesAMixtureOfSingleJobsAndCollectionsOfJobs(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $failer->expects('find')->with('batch')->returns(new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]));

        $failer->expects('find')->with('9')->returns($this->failedJob(id: '9', connection: 'database', queue: 'default'));

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->expects('forget')->with('job-1');

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-2'), 'default', []);
        $failer->expects('forget')->with('job-2');

        $queue->expects('pushRaw')->with($this->retriedPayload(id: '9'), 'default', []);
        $failer->expects('forget')->with('9');

        $this->runRetryCommand(['id' => ['batch', '9']], $failer, ['database' => $queue]);
    }

    public function testForgetsJobsUsingTheCollectionKeyRatherThanTheJobId(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $key = 'https://cloud.test/failed-jobs/batch-1:job-1';

        $failer->expects('find')->with('batch')->returns(new Collection([
            $key => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
        ]));

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->expects('forget')->with($key);

        $output = $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);

        $this->assertMatchesRegularExpression('/^  ' . preg_quote($key, '/') . ' \.+/m', $output);
    }

    public function testForgetsJobsUsingTheCollectionKeyWhenTheCollectionIsNotKeyed(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $failer->expects('find')->with('batch')->returns(new Collection([
            $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]));

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->expects('forget')->with(0);

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-2'), 'default', []);
        $failer->expects('forget')->with(1);

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);
    }

    public function testDispatchesRetryRequestedEventForEveryJobInACollection(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();
        $events = Double::for(Dispatcher::class)->strict();

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]);

        $failer->expects('find')->with('batch')->returns($jobs);

        $dispatched = [];

        $events->expects('dispatch')->times(2)->with(Argument::type(JobRetryRequested::class))->resolves(function (JobRetryRequested $event) use (&$dispatched): void {
            $dispatched[] = $event->job->id;
        });

        $queue->expects('pushRaw')->times(2);
        $failer->expects('forget')->with('job-1');
        $failer->expects('forget')->with('job-2');

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue], $events);

        $this->assertSame(['job-1', 'job-2'], $dispatched);
    }

    public function testStopsRetryingWhenAJobInACollectionFailsToBePushed(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

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

        $failer->expects('find')->with('batch')->returns($jobs);

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->expects('forget')->with('job-1');

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-2'), 'default', [])->throws(new RuntimeException('Unable to push job.'));
        $failer->expects('forget')->never()->with('job-2');

        $queue->expects('pushRaw')->never()->with($this->retriedPayload(id: 'job-3'), 'default', []);
        $failer->expects('forget')->never()->with('job-3');

        try {
            $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);

            $this->fail('The exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Unable to push job.', $e->getMessage());
        }

        $this->assertSame(['job-3'], array_keys($pendingJobs));
    }

    public function testRetriesRemainingJobsWhenAJobsModelNoLongerExists(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $missing = $this->failedJob(id: 'job-1', connection: 'database', queue: 'default', job: new QueueRetryCommandTestJobWithMissingModel);

        $jobs = new Collection([
            'job-1' => $missing,
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]);

        $failer->expects('find')->with('batch')->returns($jobs);

        $queue->expects('pushRaw')->with($missing->payload, 'default', []);
        $failer->expects('forget')->with('job-1');

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-2'), 'default', []);
        $failer->expects('forget')->with('job-2');

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);
    }

    public function testRetriesStringJobsWithoutInterpretingTheirCommandData(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();
        $data = ['command' => 'backup:run', 'commandName' => 'backup:run'];
        $job = $this->failedJob(id: '1', connection: 'database', queue: 'default');
        $job->payload = (new QueueRetryCommandCustomQueue)->payloadFor('RunCommand@handle', $data);

        $failer->expects('find')->with('1')->returns($job);
        $queue->expects('pushRaw')->with($job->payload, 'default', []);
        $failer->expects('forget')->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['database' => $queue]);
    }

    public function testRetriesJobWithoutQueueableOptionsWhenItsModelNoLongerExists(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $handler = new MockHandler([new Result(['MessageId' => 'message-1'])]);
        $sqs = $this->sqsClient($handler);
        $queue = new SqsQueue($sqs, 'default', 'https://sqs.example.test');

        $job = $this->failedJob(id: '1', connection: 'sqs', queue: 'default', job: new QueueRetryCommandTestJobWithMissingModel);

        $failer->expects('find')->with('1')->returns($job);
        $failer->expects('forget')->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['sqs' => $queue]);

        $this->assertSame([
            'QueueUrl' => 'https://sqs.example.test/default',
            'MessageBody' => $job->payload,
        ], json_decode((string) $handler->getLastRequest()->getBody(), true));
    }

    #[DataProvider('jobsWithoutRestorableObjects')]
    public function testRetriesSqsJobsWithDefaultOptionsWhenNoObjectCanBeRestored(string $queueName, array $payload): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $handler = new MockHandler([new Result(['MessageId' => 'message-1'])]);
        $sqs = $this->sqsClient($handler);
        $queue = new SqsQueue($sqs, $queueName, 'https://sqs.example.test');
        $job = $this->failedJob(
            id: '1',
            connection: 'sqs',
            queue: $queueName,
            payload: $payload,
            job: new QueueRetryCommandTestJobWithMissingModel,
        );

        $failer->expects('find')->with('1')->returns($job);
        $failer->expects('forget')->with('1');

        $this->runRetryCommand(['id' => ['1']], $failer, ['sqs' => $queue]);

        $message = json_decode((string) $handler->getLastRequest()->getBody(), true);
        $expected = [
            'QueueUrl' => 'https://sqs.example.test/' . $queueName,
            'MessageBody' => $job->payload,
        ];

        if (str_ends_with($queueName, '.fifo')) {
            $this->assertIsString($message['MessageDeduplicationId']);
            unset($message['MessageDeduplicationId']);
            $expected['MessageGroupId'] = $queueName;
        }

        $this->assertSame($expected, $message);
    }

    /**
     * Provide retry payloads whose queue options need no restored object.
     */
    public static function jobsWithoutRestorableObjects(): iterable
    {
        yield 'missing model on FIFO' => ['default.fifo', []];
        yield 'string job with command data on FIFO' => ['default.fifo', json_decode(
            (new QueueRetryCommandCustomQueue)->payloadFor('RunCommand@handle', ['command' => 'backup:run', 'commandName' => 'backup:run']),
            true,
        )];

        $incomplete = ['data' => ['commandName' => 'RemovedJob', 'command' => 'O:10:"RemovedJob":0:{}']];

        yield 'removed class on FIFO' => ['default.fifo', $incomplete];
        yield 'removed class on standard' => ['default', $incomplete];
        yield 'removed class without optional command name' => ['default.fifo', [
            'job' => 'CustomObjectHandler@call',
            'data' => ['command' => 'O:10:"RemovedJob":0:{}'],
        ]];
    }

    public function testDispatchesTheEventBeforePushingAndForgetsTheJobAfterwards(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();
        $events = Double::for(Dispatcher::class)->strict();

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
        ]);

        $failer->expects('find')->with('batch')->returns($jobs);

        $sequence = [];

        $events->expects('dispatch')->with(Argument::type(JobRetryRequested::class))->resolves(function () use (&$sequence): void {
            $sequence[] = 'dispatch';
        });

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-1'), 'default', [])->resolves(function () use (&$sequence): void {
            $sequence[] = 'push';
        });

        $failer->expects('forget')->with('job-1')->resolves(function () use (&$sequence): bool {
            $sequence[] = 'forget';

            return true;
        });

        $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue], $events);

        $this->assertSame(['dispatch', 'push', 'forget'], $sequence);
    }

    public function testOutputsAnEntryForEveryJobInACollection(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $jobs = new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'default'),
        ]);

        $failer->expects('find')->with('batch')->returns($jobs);

        $queue->expects('pushRaw')->times(2);
        $failer->expects('forget')->with('job-1');
        $failer->expects('forget')->with('job-2');

        $output = $this->runRetryCommand(['id' => ['batch']], $failer, ['database' => $queue]);

        $this->assertSame(1, substr_count($output, 'Pushing failed queue jobs back onto the queue.'));
        $this->assertSame(1, substr_count($output, 'job-1'));
        $this->assertSame(1, substr_count($output, 'job-2'));
        $this->assertSame(2, substr_count($output, 'DONE'));
    }

    public function testRetriesCollectionsOfJobsWhenRetryingAllFailedJobs(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $failer->expects('ids')->with()->returns(['batch-1', 'batch-2']);

        $failer->expects('find')->with('batch-1')->returns(new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
        ]));

        $failer->expects('find')->with('batch-2')->returns(new Collection([
            'job-2' => $this->failedJob(id: 'job-2', connection: 'database', queue: 'emails'),
        ]));

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->expects('forget')->with('job-1');

        $queue->expects('pushRaw')->with($this->retriedPayload(id: 'job-2'), 'emails', []);
        $failer->expects('forget')->with('job-2');

        $this->runRetryCommand(['id' => ['all']], $failer, ['database' => $queue]);
    }

    public function testRetriesTheSameJobTwiceWhenItAppearsInTwoCollections(): void
    {
        $failer = Double::for(FailedJobProviderInterface::class)->strict();
        $queue = Double::for(QueueContract::class)->strict();

        $failer->expects('find')->with('batch-1')->returns(new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
        ]));

        $failer->expects('find')->with('batch-2')->returns(new Collection([
            'job-1' => $this->failedJob(id: 'job-1', connection: 'database', queue: 'default'),
        ]));

        $queue->expects('pushRaw')->times(2)->with($this->retriedPayload(id: 'job-1'), 'default', []);
        $failer->expects('forget')->times(2)->with('job-1');

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
     * Create an SQS client that records requests without contacting AWS.
     */
    private function sqsClient(MockHandler $handler): SqsClient
    {
        return new SqsClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
        ]);
    }

    /**
     * Run the retry command with the given provider and connections.
     */
    private function runRetryCommand(array $input, FailedJobProviderInterface $failer, array $connections, ?Dispatcher $events = null): string
    {
        $container = new Application;

        $container->instance('queue.failer', $failer);

        $manager = Double::for(QueueManager::class)->strict();

        foreach ($connections as $name => $queue) {
            $manager->allows('connection')->with($name)->returns($queue);
        }

        $container->instance('queue', $manager);

        if ($events !== null) {
            $events->allows('hasListeners')->resolves(static fn (string $event): bool => $event === JobRetryRequested::class);
            $container->instance('events', $events);
        }

        $command = new RetryCommand;
        $command->setHypervel($container);

        $output = new BufferedOutput;
        $command->run(new ArrayInput($input), $output);

        return $output->fetch();
    }
}

class QueueRetryCommandCustomQueue extends SyncQueue
{
    /**
     * Build payloads through the real queue producer.
     */
    public function payloadFor(object|string $job, mixed $data = ''): string
    {
        return $this->createPayload($job, 'default', $data);
    }

    /**
     * Replace the object handler as a custom queue driver may do.
     */
    protected function createObjectPayload(object $job, ?string $queue): array
    {
        return array_replace(parent::createObjectPayload($job, $queue), ['job' => 'CustomObjectHandler@call']);
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

#[RetryFor(90)]
class QueueRetryCommandTestJobWithRetryForAttribute
{
}

#[RetryFor(90)]
class QueueRetryCommandTestJobWithRetryForAttributeAndRetryUntilProperty
{
    public int $retryUntil = 1234567890;
}

class QueueRetryCommandTestListener implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(): void
    {
    }
}

#[RetryFor(90)]
class QueueRetryCommandTestListenerWithRetryForAttribute extends QueueRetryCommandTestListener
{
}

#[RetryFor(90)]
class QueueRetryCommandTestListenerWithRetryUntil extends QueueRetryCommandTestListener
{
    /**
     * Return the retry deadline carried by the event.
     */
    public function retryUntil(int $retryUntil): int
    {
        return $retryUntil;
    }
}

class QueueRetryCommandTestListenerWithRelativeRetryUntil extends QueueRetryCommandTestListener
{
    /**
     * Return a retry deadline relative to now.
     */
    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes(10);
    }
}

class QueueRetryCommandTestJobWithMissingModel
{
    /**
     * Restore a job whose model has been deleted.
     */
    public function __unserialize(array $data): void
    {
        throw (new ModelNotFoundException)->setModel('App\Models\User', [1]);
    }
}
