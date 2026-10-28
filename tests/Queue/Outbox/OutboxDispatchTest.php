<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue\Outbox;

use Aws\Sqs\SqsClient;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Contracts\Queue\ShouldQueueViaOutbox;
use Hypervel\Contracts\Redis\Factory;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Queue\BeanstalkdQueue;
use Hypervel\Queue\DatabaseQueue;
use Hypervel\Queue\Events\JobPayloadFinalizing;
use Hypervel\Queue\Events\JobQueued;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Queue\Events\JobQueueingFailed;
use Hypervel\Queue\InvalidPayloadException;
use Hypervel\Queue\Outbox\OutboxDispatcher;
use Hypervel\Queue\Queue;
use Hypervel\Queue\RedisQueue;
use Hypervel\Queue\SqsQueue;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Pheanstalk\Contract\PheanstalkManagerInterface;
use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Contract\PheanstalkSubscriberInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use UnitEnum;

class OutboxDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    /**
     * Configure the outbox source for the test database.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('queue.outbox.databases', [$config->string('database.default')]);
    }

    /**
     * Load only the shipped outbox migration.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--realpath' => true,
            '--path' => dirname(__DIR__, 3) . '/src/testbench/hypervel/migrations/0001_01_01_000009_testbench_create_job_outbox_table.php',
        ];
    }

    public function testSingleStagingPreservesFinalizedPayloadAndLifecycle(): void
    {
        $queue = $this->queue();
        $events = $this->app->make('events');
        $finalized = 0;
        $observed = [];
        $events->listen(JobPayloadFinalizing::class, function (JobPayloadFinalizing $event) use (&$finalized): void {
            ++$finalized;
            $event->payload = json_encode([...json_decode($event->payload, true), 'custom' => 'retained']);
        });
        $events->listen(JobQueueing::class, function (JobQueueing $event) use (&$observed): void {
            $observed[] = $event->payload;
        });
        $events->listen(JobQueued::class, function (JobQueued $event) use (&$observed): void {
            $observed[] = $event->payload;
        });

        $id = $queue->push((new OutboxDispatchJob)->viaOutbox()->beforeCommit());
        $row = DB::table('job_outbox')->sole();

        $this->assertSame($row->uuid, $id);
        $this->assertSame($id, json_decode($row->payload, true)['uuid']);
        $this->assertSame([$row->payload, $row->payload], $observed);
        $this->assertSame(1, $finalized);
        $this->assertSame('redis', $row->connection);
        $this->assertSame('orders', $row->queue);
        $this->assertNull($row->options);
        $this->assertSame([], $queue->published);
    }

    public function testSelectionPrecedenceAndDirectDispatchAvoidOutboxResolution(): void
    {
        $queue = $this->queue();
        $this->assertFalse($this->app->resolved(OutboxDispatcher::class));
        $queue->push(new OutboxDispatchJob);
        $queue->push((new MarkedOutboxDispatchJob)->withoutOutbox());
        $queue->setConfig(['outbox' => true]);
        $queue->push((new OutboxDispatchJob)->withoutOutbox());
        $this->assertFalse($this->app->resolved(OutboxDispatcher::class));
        $this->assertCount(3, $queue->published);

        $queue->push(new OutboxDispatchJob);
        $queue->setConfig([]);
        $queue->push(new MarkedOutboxDispatchJob);
        $queue->push((new OutboxDispatchJob)->viaOutbox());

        $this->assertSame(3, DB::table('job_outbox')->count());
        $this->assertCount(3, $queue->published);
    }

    public function testRawPublicationNeverStages(): void
    {
        $queue = $this->queue()->setConfig(['outbox' => true]);
        $queue->pushRaw('{"uuid":"raw"}');

        $this->assertFalse($this->app->resolved(OutboxDispatcher::class));
        $this->assertSame(['{"uuid":"raw"}'], $queue->published);
    }

    public function testBulkStagingUsesOneInsertAndFinalizesEachPayloadOnce(): void
    {
        $queue = $this->queue();
        $finalized = 0;
        $this->app->make('events')->listen(JobPayloadFinalizing::class, function () use (&$finalized): void {
            ++$finalized;
        });
        $jobs = array_map(static fn (): OutboxDispatchJob => (new OutboxDispatchJob)->viaOutbox(), range(1, 100));
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        $this->assertNull($queue->bulk($jobs));
        $inserts = array_filter($connection->getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'insert into'));
        $connection->disableQueryLog();

        $this->assertCount(1, $inserts);
        $this->assertSame(100, $finalized);
        $this->assertSame(100, DB::table('job_outbox')->count());
        $this->assertSame([], $queue->published);
    }

    #[DataProvider('baseBulkDrivers')]
    public function testBaseBulkStagesOneInsertAndKeepsTheDirectPartition(bool $beanstalk): void
    {
        $queue = $beanstalk
            ? m::mock(BeanstalkdQueue::class, [m::mock(PheanstalkManagerInterface::class . ', ' . PheanstalkPublisherInterface::class . ', ' . PheanstalkSubscriberInterface::class), 'orders', 60])->makePartial()
            : m::mock(Queue::class . ', ' . QueueContract::class)->makePartial();
        $queue->setContainer($this->app)->setConnectionName($beanstalk ? 'beanstalkd' : 'custom');
        $direct = (new OutboxDispatchJob)->withoutOutbox();
        $queue->expects('push')->with($direct, '', 'orders')->andReturn('direct');
        $jobs = array_map(static fn (): OutboxDispatchJob => (new OutboxDispatchJob)->viaOutbox(), range(1, 100));
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();

        try {
            $queue->bulk([...$jobs, $direct], queue: 'orders');
            $inserts = array_filter($connection->getQueryLog(), static fn (array $query): bool => str_starts_with($query['query'], 'insert into'));
        } finally {
            $connection->disableQueryLog();
        }

        $this->assertCount(1, $inserts);
        $this->assertSame(100, DB::table('job_outbox')->count());
        $this->assertSame(['orders'], DB::table('job_outbox')->distinct()->pluck('queue')->all());
    }

    /**
     * Cover the first-party and custom users of the shared bulk implementation.
     */
    public static function baseBulkDrivers(): array
    {
        return [[true], [false]];
    }

    public function testBulkPreparationFailureStoresNothingAndTerminatesEveryMember(): void
    {
        $queue = $this->queue();
        $failure = new RuntimeException('Finalization failed');
        $finalized = 0;
        $failed = [];
        $this->app->make('events')->listen(JobPayloadFinalizing::class, function () use (&$finalized, $failure): void {
            if (++$finalized === 2) {
                throw $failure;
            }
        });
        $this->app->make('events')->listen(JobQueueingFailed::class, function (JobQueueingFailed $event) use (&$failed): void {
            $failed[] = $event->job;
        });
        $jobs = [(new OutboxDispatchJob)->viaOutbox(), (new OutboxDispatchJob)->viaOutbox(), (new OutboxDispatchJob)->viaOutbox()];

        try {
            $queue->bulk($jobs);
            $this->fail('Expected finalization to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($jobs, $failed);
        $this->assertSame(0, DB::table('job_outbox')->count());
    }

    public function testBulkFailureIsNotReplacedByAFailingFailureListener(): void
    {
        $queue = $this->queue();
        $failure = new RuntimeException('Finalization failed');
        $listener = new RuntimeException('Listener failed');
        $failed = [];
        $this->app->make('events')->listen(JobPayloadFinalizing::class, static fn () => throw $failure);
        $this->app->make('events')->listen(JobQueueingFailed::class, function (JobQueueingFailed $event) use (&$failed): void {
            $failed[] = $event->job;
        });
        $this->app->make('events')->listen(JobQueueingFailed::class, static fn () => throw $listener);
        $handler = m::mock(ExceptionHandler::class);
        $handler->expects('report')->with($listener);
        $this->app->instance(ExceptionHandler::class, $handler);
        $jobs = [(new OutboxDispatchJob)->viaOutbox(), (new OutboxDispatchJob)->viaOutbox()];

        try {
            $queue->bulk($jobs);
            $this->fail('Expected finalization to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($jobs, $failed);
        $this->assertSame(0, DB::table('job_outbox')->count());
    }

    public function testAcceptedBulkIsNotFailedWhenItsQueuedListenerThrows(): void
    {
        $queue = $this->queue();
        $failure = new RuntimeException('Observer failed');
        $notified = 0;
        $failed = 0;
        $this->app->make('events')->listen(JobQueued::class, function () use (&$notified, $failure): void {
            ++$notified;
            throw $failure;
        });
        $this->app->make('events')->listen(JobQueueingFailed::class, function () use (&$failed): void {
            ++$failed;
        });

        try {
            $queue->bulk([(new OutboxDispatchJob)->viaOutbox(), (new OutboxDispatchJob)->viaOutbox()]);
            $this->fail('Expected the observer error.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame(2, $notified);
        $this->assertSame(0, $failed);
        $this->assertSame(2, DB::table('job_outbox')->count());
    }

    #[DataProvider('deliveryTimes')]
    public function testStagingRetainsRequestedTimersWithoutPublishingBeforeTheyAreDue(string $kind, ?int $offset): void
    {
        Date::setTestNow('2040-01-01 12:00:00.500000');
        $queue = $this->queue();
        $delay = match ($kind) {
            'none' => null,
            'zero' => 0,
            'past' => Date::now()->subSecond(),
            'future' => Date::now()->addMilliseconds(250),
        };
        $job = (new OutboxDispatchJob)->viaOutbox();
        $delay === null ? $queue->push($job) : $queue->later($delay, $job);
        $row = DB::table('job_outbox')->sole();

        $this->assertSame($offset === null ? null : Date::now()->getTimestamp() + $offset, $row->deliver_at === null ? null : (int) $row->deliver_at);
        $this->assertSame(Date::now()->getTimestamp(), (int) $row->available_at);
    }

    /**
     * Provide timer selection and absolute deadline normalization cases.
     */
    public static function deliveryTimes(): array
    {
        return [['none', null], ['zero', null], ['past', 0], ['future', 1]];
    }

    #[DataProvider('sqsDelays')]
    public function testSqsStagingCapturesOptionsOnceAndWaitsForItsTransportWindow(string $method, bool $fifo, int $eligibleDelay): void
    {
        Date::setTestNow('2040-01-01 12:00:00');
        $queue = new SqsQueue(m::mock(SqsClient::class), $fifo ? 'orders.fifo' : 'orders');
        $queue->setContainer($this->app)->setConnectionName('sqs');
        $job = (new OutboxFifoDispatchJob)->viaOutbox()->onGroup('tenant:17')->delay(1200);

        $method === 'bulk' ? $queue->bulk([$job]) : $queue->later(1200, $job);
        $row = DB::table('job_outbox')->sole();
        $options = json_decode($row->options, true)['transport']['sqs'];

        $this->assertSame(Date::now()->getTimestamp() + 1200, (int) $row->deliver_at);
        $this->assertSame(Date::now()->getTimestamp() + $eligibleDelay, (int) $row->available_at);
        $this->assertSame('tenant:17', $options['MessageGroupId']);
        $this->assertArrayNotHasKey('DelaySeconds', $options);
        $this->assertSame($fifo ? 1 : 0, $job->deduplications);

        if ($fifo) {
            $this->assertSame('captured-' . $row->uuid, $options['MessageDeduplicationId']);
        }
    }

    /**
     * Exercise both SQS entry points and their distinct eligibility rules.
     */
    public static function sqsDelays(): array
    {
        return [['later', false, 300], ['bulk', false, 300], ['later', true, 1200], ['bulk', true, 1200]];
    }

    public function testInvalidFinalUuidFailsBeforeStorage(): void
    {
        $this->app->make('events')->listen(JobPayloadFinalizing::class, function (JobPayloadFinalizing $event): void {
            $event->payload = '{"uuid":"not-a-uuid"}';
        });

        try {
            $this->queue()->push((new OutboxDispatchJob)->viaOutbox());
            $this->fail('Expected an invalid payload.');
        } catch (InvalidPayloadException $exception) {
            $this->assertSame('The outbox payload must contain a valid job UUID.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('job_outbox')->count());
    }

    public function testDatabaseFastPathResolutionFailureEmitsTheFailedLifecycle(): void
    {
        $queue = new DatabaseQueue($this->app->make(ConnectionResolverInterface::class), null, 'jobs');
        $queue->setContainer($this->app)->setConnectionName('database');
        $job = (new OutboxDispatchJob)->onOutboxDatabase('missing-owner');
        $failed = [];
        $this->app->make('events')->listen(JobQueueingFailed::class, function (JobQueueingFailed $event) use (&$failed): void {
            $failed[] = $event;
        });

        DB::beginTransaction();

        try {
            $queue->push($job);
            $this->fail('Expected the invalid database configuration to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertCount(1, $failed);
            $this->assertSame($job, $failed[0]->job);
            $this->assertSame($exception, $failed[0]->exception);
        } finally {
            DB::rollBack();
        }

        $this->assertFalse($this->app->resolved(OutboxDispatcher::class));
        $this->assertSame(0, DB::table('job_outbox')->count());
    }

    /**
     * Create a Redis queue that records direct publication without network access.
     */
    protected function queue(): RecordingOutboxRedisQueue
    {
        $queue = new RecordingOutboxRedisQueue(m::mock(Factory::class), 'orders');
        $queue->setContainer($this->app)->setConnectionName('redis');

        return $queue;
    }
}

class OutboxDispatchJob
{
    use Queueable;
}

class MarkedOutboxDispatchJob extends OutboxDispatchJob implements ShouldQueueViaOutbox
{
}

class OutboxFifoDispatchJob extends OutboxDispatchJob
{
    public int $deduplications = 0;

    /**
     * Capture the finalized UUID while the original job is available.
     */
    public function deduplicationId(string $payload, string $queue): string
    {
        ++$this->deduplications;

        return 'captured-' . json_decode($payload, true)['uuid'];
    }
}

class RecordingOutboxRedisQueue extends RedisQueue
{
    public array $published = [];

    /**
     * Record a direct raw publication.
     */
    public function pushRaw(string $payload, UnitEnum|string|null $queue = null, array $options = []): mixed
    {
        $this->published[] = $payload;

        return json_decode($payload, true)['uuid'];
    }
}
