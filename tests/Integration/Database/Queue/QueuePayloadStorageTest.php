<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Queue;

use Hypervel\Queue\Failed\DatabaseUuidFailedJobProvider;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Str;
use Hypervel\Testbench\Concerns\InteractsWithPublishedFiles;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use RuntimeException;

class QueuePayloadStorageTest extends DatabaseTestCase
{
    // DatabaseMigrations rolls back before this trait removes the generated files.
    use InteractsWithPublishedFiles;

    public function testGeneratedTablesPreserveRawPayloadsAndSuppliedIdentifiers(): void
    {
        // Generate after the published-file trait snapshots existing migrations.
        // Use the shipped stubs rather than preloading the Testbench schema.
        $this->artisan('make:queue-table')->assertExitCode(0);
        $this->artisan('make:queue-failed-table')->assertExitCode(0);
        $this->artisan('migrate')->assertExitCode(0);

        $queue = Queue::connection('database');
        $provider = new DatabaseUuidFailedJobProvider($this->app->make('db'), null, 'failed_jobs');

        foreach ([
            [null, '{invalid'],
            // A native UUID column would reject this supported identifier on PostgreSQL.
            ['uuid-1', '{ "uuid": "uuid-1", "job": "ExampleJob", "data": {"b":2,"a":1} }'],
        ] as [$identifier, $payload]) {
            $queue->pushRaw($payload);

            $job = $queue->pop();

            $this->assertNotNull($job);
            $this->assertSame($payload, $job->getRawBody());

            $failedId = $provider->log('database', $job->getQueue(), $job->getRawBody(), new RuntimeException);

            if ($identifier === null) {
                $this->assertTrue(Str::isUuid($failedId));
            } else {
                $this->assertSame($identifier, $failedId);
            }

            $failedJob = $provider->find($failedId);

            $this->assertNotNull($failedJob);
            $this->assertSame($payload, $failedJob->payload);

            $job->delete();
        }
    }
}
