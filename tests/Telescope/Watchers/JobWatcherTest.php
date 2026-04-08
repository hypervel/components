<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Closure;
use Exception;
use Hypervel\Bus\Batch;
use Hypervel\Bus\BatchRepository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Queue\Events\JobFailed;
use Hypervel\Queue\Events\JobProcessed;
use Hypervel\Queue\Jobs\FakeJob;
use Hypervel\Support\Facades\Bus;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Jobs\ProcessPendingUpdates;
use Hypervel\Telescope\Watchers\JobWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
#[WithConfig('telescope.watchers', [
    JobWatcher::class => true,
])]
class JobWatcherTest extends FeatureTestCase
{
    public function testJobRegistersProcessingEntry()
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

        MockedBatchableJob::dispatch('batch-id');

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::JOB, $entry->type);
        $this->assertSame('batch-id', $entry->family_hash);
        $this->assertSame('processed', $entry->content['status']);
        $this->assertSame('sync', $entry->content['connection']);
        $this->assertSame('default', $entry->content['queue']);
        $this->assertSame(MockedBatchableJob::class, $entry->content['name']);
    }

    public function testJobRegistersEntry()
    {
        Bus::fake();

        $this->app->make(Dispatcher::class)
            ->dispatch(
                new JobProcessed(
                    'connection',
                    new FakeJob([
                        'telescope_uuid' => 'uuid',
                    ])
                )
            );

        $this->loadTelescopeEntries();

        Bus::assertDispatched(ProcessPendingUpdates::class, function ($job) {
            $entry = $job->pendingUpdates->first();
            $this->assertSame(EntryType::JOB, $entry->type);
            $this->assertSame('processed', $entry->changes['status']);

            return true;
        });
    }

    public function testFailedJobsRegisterEntry()
    {
        Bus::fake();

        $this->app->make(Dispatcher::class)
            ->dispatch(
                new JobFailed(
                    'connection',
                    new FakeJob([
                        'telescope_uuid' => 'uuid',
                    ]),
                    new Exception($message = 'I never watched Star Wars.')
                )
            );

        $this->loadTelescopeEntries();

        Bus::assertDispatched(ProcessPendingUpdates::class, function ($job) use ($message) {
            $entry = $job->pendingUpdates->first();
            $this->assertSame(EntryType::JOB, $entry->type);
            $this->assertSame('failed', $entry->changes['status']);
            $this->assertSame($message, $entry->changes['exception']['message']);

            return true;
        });
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
