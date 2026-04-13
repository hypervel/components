<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Closure;
use Exception;
use Hypervel\Bus\Batch;
use Hypervel\Bus\BatchRepository;
use Hypervel\Contracts\Bus\Dispatcher;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Queue\Jobs\Job;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Str;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Watchers\JobWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\Factories\UserFactory;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
#[WithMigration('queue')]
#[WithConfig('queue.failed.database', 'testing')]
#[WithConfig('logging.default', 'null')]
#[WithConfig('telescope.watchers', [
    JobWatcher::class => true,
])]
class JobWatcherTest extends FeatureTestCase
{
    public function testJobRegistersEntry()
    {
        $this->app->make(Dispatcher::class)->dispatch(new MyDatabaseJob('Awesome Laravel'));

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--queue' => 'on-demand',
        ])->run();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::JOB, $entry->type);
        $this->assertSame('processed', $entry->content['status']);
        $this->assertSame('database', $entry->content['connection']);
        $this->assertSame(MyDatabaseJob::class, $entry->content['name']);
        $this->assertSame('on-demand', $entry->content['queue']);
        $this->assertSame('Awesome Laravel', $entry->content['data']['payload']);
    }

    public function testJobRegistersEntryWithBatchIdInPayload()
    {
        $this->app->make(Dispatcher::class)->dispatch(new MockedBatchableJob($batchId = (string) Str::orderedUuid()));

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--queue' => 'on-demand',
        ])->run();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::JOB, $entry->type);
        $this->assertSame('processed', $entry->content['status']);
        $this->assertSame('database', $entry->content['connection']);
        $this->assertSame(MockedBatchableJob::class, $entry->content['name']);
        $this->assertSame('on-demand', $entry->content['queue']);
        $this->assertSame($batchId, $entry->content['data']['batchId']);
    }

    public function testFailedJobsRegisterEntry()
    {
        $this->app->make(Dispatcher::class)->dispatch(
            new MyFailedDatabaseJob('I never watched Star Wars.')
        );

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
        ])->run();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::JOB, $entry->type);
        $this->assertSame('failed', $entry->content['status']);
        $this->assertSame('database', $entry->content['connection']);
        $this->assertSame(MyFailedDatabaseJob::class, $entry->content['name']);
        $this->assertSame('default', $entry->content['queue']);
        $this->assertSame('I never watched Star Wars.', $entry->content['data']['message']);
        $this->assertArrayHasKey('exception', $entry->content);

        $this->assertArrayNotHasKey('args', $entry->content['exception']['trace'][0]);
        $this->assertSame(MyFailedDatabaseJob::class, $entry->content['exception']['trace'][0]['class']);
        $this->assertSame('handle', $entry->content['exception']['trace'][0]['function']);
    }

    public function testItHandlesPushedJobs()
    {
        $queueExceptions = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$queueExceptions) {
            $queueExceptions[] = $e;
        });

        $this->app->make(QueueManager::class)
            ->connection('database')
            ->push(MyPushedJobClass::class, ['framework' => 'Laravel']);
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
        ]);

        $entry = $this->loadTelescopeEntries()->first();
        $this->assertCount(1, $queueExceptions);
        $this->assertInstanceOf(PushedJobFailedException::class, $queueExceptions[0]);
        $this->assertSame(EntryType::JOB, $entry->type);
        $this->assertSame('failed', $entry->content['status']);
        $this->assertSame('database', $entry->content['connection']);
        $this->assertSame(MyPushedJobClass::class, $entry->content['name']);
        $this->assertSame('default', $entry->content['queue']);
        $this->assertSame(['framework' => 'Laravel'], $entry->content['data']);
    }

    public function testJobCanHandleDeletedSerializedModel()
    {
        $user = UserFactory::new()->create();

        $this->app->make(Dispatcher::class)->dispatch(
            new MockedDeleteUserJob($user)
        );

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
        ])->run();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::JOB, $entry->type);
        $this->assertSame('processed', $entry->content['status']);
        $this->assertSame('database', $entry->content['connection']);
        $this->assertSame(MockedDeleteUserJob::class, $entry->content['name']);
        $this->assertSame('default', $entry->content['queue']);

        $this->assertSame(sprintf('%s:%s', get_class($user), $user->getKey()), $entry->content['data']['user']);
    }

    public function testJobRegistersProcessingEntryWithBatchFamilyHash()
    {
        $batch = m::mock(Batch::class);
        $batch->shouldReceive('toArray')
            ->once()
            ->andReturn(['foo' => 'bar']);
        $batchRepository = m::mock(BatchRepository::class);
        $batchRepository->shouldReceive('find')
            ->with('batch-id')
            ->once()
            ->andReturn($batch);

        $this->app->instance(BatchRepository::class, $batchRepository);

        MockedSyncBatchableJob::dispatch('batch-id');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::JOB, $entry->type);
        $this->assertSame('batch-id', $entry->family_hash);
        $this->assertSame('processed', $entry->content['status']);
        $this->assertSame('sync', $entry->content['connection']);
        $this->assertSame('default', $entry->content['queue']);
        $this->assertSame(MockedSyncBatchableJob::class, $entry->content['name']);
    }

    public function testJobRecordsDispatchTimeContextInDataHiddenShape()
    {
        ContextRepository::getInstance()->add('trace_id', 'abc-123');
        ContextRepository::getInstance()->addHidden('api_key', 'secret');

        MockedSimpleJob::dispatch();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertIsArray($entry->content['context']);
        $this->assertSame(['trace_id' => 'abc-123'], $entry->content['context']['data']);
        $this->assertSame(['api_key' => 'secret'], $entry->content['context']['hidden']);
    }

    public function testJobProcessedUpdateOverwritesDispatchTimeContext()
    {
        ContextRepository::getInstance()->add('dispatch_key', 'dispatch_value');

        MockedContextJob::$runtimeCallback = function () {
            ContextRepository::getInstance()->flush();
            ContextRepository::getInstance()->add('runtime_key', 'runtime_value');
        };

        MockedContextJob::dispatch();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(['runtime_key' => 'runtime_value'], $entry->content['context']['data']);
        $this->assertArrayNotHasKey('dispatch_key', $entry->content['context']['data']);
    }

    public function testJobEmptyRuntimeContextClearsDispatchTimeContext()
    {
        ContextRepository::getInstance()->add('dispatch_key', 'dispatch_value');

        MockedContextJob::$runtimeCallback = function () {
            ContextRepository::getInstance()->flush();
        };

        MockedContextJob::dispatch();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertNull($entry->content['context']);
    }

    public function testJobHiddenOnlyContextIsPreserved()
    {
        ContextRepository::getInstance()->addHidden('secret_key', 'secret_value');

        MockedSimpleJob::dispatch();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertIsArray($entry->content['context']);
        $this->assertSame([], $entry->content['context']['data']);
        $this->assertSame(['secret_key' => 'secret_value'], $entry->content['context']['hidden']);
    }

    public function testJobPreservesFalsyFields()
    {
        MockedZeroValuesJob::dispatch();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertArrayHasKey('data', $entry->content);
        $this->assertArrayHasKey('connection', $entry->content);
        $this->assertArrayHasKey('queue', $entry->content);
        $this->assertArrayHasKey('tries', $entry->content);
        $this->assertArrayHasKey('timeout', $entry->content);
        $this->assertSame(0, $entry->content['tries']);
        $this->assertSame(0, $entry->content['timeout']);
    }
}

class MockedBatchableJob implements ShouldQueue
{
    public $connection = 'database';

    public $queue = 'on-demand';

    public $batchId;

    public function __construct($batchId)
    {
        $this->batchId = $batchId;
    }

    public function handle()
    {
    }
}

class MockedSyncBatchableJob implements ShouldQueue
{
    use Dispatchable;

    public $batchId;

    public function __construct($batchId)
    {
        $this->batchId = $batchId;
    }

    public function handle()
    {
    }
}

class MockedDeleteUserJob implements ShouldQueue
{
    use SerializesModels;

    public $connection = 'database';

    public $deleteWhenMissingModels = true;

    public $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function handle()
    {
        $this->user->delete();
    }
}

class MyDatabaseJob implements ShouldQueue
{
    public $connection = 'database';

    public $queue = 'on-demand';

    private $payload;

    public function __construct($payload)
    {
        $this->payload = $payload;
    }

    public function handle()
    {
    }
}

class MyFailedDatabaseJob implements ShouldQueue
{
    public $connection = 'database';

    public $tries = 1;

    private $message;

    public function __construct($message)
    {
        $this->message = $message;
    }

    public function handle()
    {
        throw new Exception($this->message);
    }
}

class MyPushedJobClass
{
    public $tries = 1;

    public function fire(Job $job, array $data)
    {
        throw new PushedJobFailedException;
    }
}

class PushedJobFailedException extends Exception
{
}

class MockedSimpleJob implements ShouldQueue
{
    use Dispatchable;

    public function handle()
    {
    }
}

class MockedContextJob implements ShouldQueue
{
    use Dispatchable;

    public static ?Closure $runtimeCallback = null;

    public function handle()
    {
        if (static::$runtimeCallback) {
            (static::$runtimeCallback)();
            static::$runtimeCallback = null;
        }
    }
}

class MockedZeroValuesJob implements ShouldQueue
{
    use Dispatchable;

    public int $tries = 0;

    public int $timeout = 0;

    public function handle()
    {
    }
}
