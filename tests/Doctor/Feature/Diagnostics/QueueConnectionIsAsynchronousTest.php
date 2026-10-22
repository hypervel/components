<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\QueueConnectionIsAsynchronous;
use Hypervel\Doctor\Doctor;
use Hypervel\Doctor\Results\Link;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class QueueConnectionIsAsynchronousTest extends TestCase
{
    public function testPassesWhenQueuesRunSynchronously(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        config([
            'queue.default' => 'sync',
        ]);

        $result = (new QueueConnectionIsAsynchronous)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame('Queued jobs run synchronously.', $result->summary);
    }

    public function testWarnsWhenQueuesRunSynchronouslyInProduction(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['queue.default' => 'sync']);

        $result = (new QueueConnectionIsAsynchronous)->check();

        $this->assertSame('warn', $result->status->value);
        $this->assertSame('Queued jobs run synchronously in production.', $result->summary);
        $this->assertEquals([Link::docs('queues', 'connections-vs-queues')], $result->links);
    }

    public function testWarnsWhenQueuesRunSynchronouslyInUnmappedEnvironments(): void
    {
        $this->app->detectEnvironment(fn (): string => 'staging');
        config(['queue.default' => 'sync']);

        $result = (new QueueConnectionIsAsynchronous)->check();

        $this->assertSame('warn', $result->status->value);
        $this->assertSame('Queued jobs run synchronously in production.', $result->summary);
    }

    public function testNoticesWhenQueuedJobsAreProcessedAsynchronouslyLocally(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');

        config([
            'queue.default' => 'database',
        ]);

        $result = (new QueueConnectionIsAsynchronous)->check();

        $this->assertSame('notice', $result->status->value);
        $this->assertSame('Queued jobs are processed asynchronously.', $result->summary);
        $this->assertSame('If jobs are not being processed, make sure a queue worker is running via `php artisan queue:work`.', $result->remediation);
        $this->assertEquals([Link::docs('queues', 'running-the-queue-worker')], $result->links);
    }

    public function testPassesWhenQueuedJobsAreProcessedAsynchronouslyOutsideLocalEnvironments(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['queue.default' => 'database']);

        $result = (new QueueConnectionIsAsynchronous)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame('Queued jobs are processed asynchronously.', $result->summary);
    }

    public function testSkipsWhenTheDefaultQueueConnectionIsNotConfigured(): void
    {
        config(['queue.default' => null]);

        $result = (new QueueConnectionIsAsynchronous)->check();

        $this->assertSame('skip', $result->status->value);
        $this->assertSame('The application does not have a default queue connection configured.', $result->summary);
    }

    #[DataProvider('inProcessQueues')]
    public function testInProcessQueuesNeedNoExternalWorker(string $driver, string $environment): void
    {
        $this->app->detectEnvironment(fn (): string => $environment);
        config(['queue.default' => 'custom', 'queue.connections.custom' => ['driver' => $driver]]);

        $result = (new QueueConnectionIsAsynchronous)->check();

        $this->assertSame('pass', $result->status->value);
        $this->assertSame('queue-connection-is-asynchronous.in-process', $result->code);
        $this->assertNull($result->remediation);
    }

    /**
     * Provide coroutine queues in both environment modes.
     */
    public static function inProcessQueues(): array
    {
        return [
            ['background', 'local'], ['background', 'production'],
            ['deferred', 'local'], ['deferred', 'production'],
        ];
    }

    public function testUsesTheFirstConcreteFailoverChildsDriver(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config([
            'queue.default' => 'main',
            'queue.connections.main' => ['driver' => 'failover', 'connections' => ['nested', 'database']],
            'queue.connections.nested' => ['driver' => 'failover', 'connections' => ['primary']],
            'queue.connections.primary' => ['driver' => 'sync'],
        ]);

        $result = (new QueueConnectionIsAsynchronous)->check();

        $this->assertSame('warn', $result->status->value);
        $this->assertSame('queue-connection-is-asynchronous.sync-production', $result->code);
    }

    public function testCycleBesideAHealthyChildCannotPassDiscovery(): void
    {
        config([
            'queue.default' => 'main',
            'queue.connections.main' => ['driver' => 'failover', 'connections' => ['main', 'database']],
        ]);

        $result = $this->app->make(Doctor::class)->check(new QueueConnectionIsAsynchronous)->result;

        $this->assertSame('error', $result->status->value);
        $this->assertSame('Circular queue connection definition detected: main -> main.', $result->summary);
    }

    public function testEmptyFailoverCannotPassAsynchronousCheck(): void
    {
        config(['queue.default' => 'empty', 'queue.connections.empty' => ['driver' => 'failover', 'connections' => []]]);

        $this->assertSame('fail', (new QueueConnectionIsAsynchronous)->check()->status->value);
    }

    #[DataProvider('nullQueues')]
    public function testNullQueuesAreReportedAsIntentionallyDisabled(string $connection, array $connections): void
    {
        config(['queue.default' => $connection, 'queue.connections' => $connections]);

        $result = (new QueueConnectionIsAsynchronous)->check();

        $this->assertSame('skip', $result->status->value);
        $this->assertSame('queue-connection-is-asynchronous.disabled', $result->code);
    }

    /**
     * Provide the built-in null connection and explicitly configured null drivers.
     */
    public static function nullQueues(): array
    {
        return [
            ['null', []],
            ['disabled', ['disabled' => ['driver' => 'null']]],
            ['main', ['main' => ['driver' => 'failover', 'connections' => ['null']]]],
        ];
    }
}
