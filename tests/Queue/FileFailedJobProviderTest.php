<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Exception;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Queue\Failed\FileFailedJobProvider;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;

class FileFailedJobProviderTest extends TestCase
{
    protected string $tempDirectory;

    protected string $path;

    protected FileFailedJobProvider $provider;

    protected Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->tempDirectory = ParallelTesting::tempDir('FileFailedJobProviderTest');
        mkdir($this->tempDirectory, 0777, true);
        $this->path = $this->tempDirectory . '/failed-jobs.json';
        $this->provider = new FileFailedJobProvider($this->path);
    }

    protected function tearDown(): void
    {
        $this->filesystem->deleteDirectory($this->tempDirectory);

        parent::tearDown();
    }

    public function testCanLogFailedJobs(): void
    {
        [$uuid, $exception] = $this->logFailedJob();

        $failedJobs = $this->provider->all();

        $this->assertEquals([
            (object) [
                'id' => $uuid,
                'connection' => 'connection',
                'queue' => 'queue',
                'payload' => json_encode(['uuid' => $uuid]),
                'exception' => (string) mb_convert_encoding((string) $exception, 'UTF-8'),
                'failed_at' => $failedJobs[0]->failed_at,
                'failed_at_timestamp' => $failedJobs[0]->failed_at_timestamp,
            ],
        ], $failedJobs);
    }

    public function testCanRetrieveAllFailedJobs(): void
    {
        try {
            CarbonImmutable::setTestNow(now());

            [$uuidOne, $exceptionOne] = $this->logFailedJob();
            [$uuidTwo, $exceptionTwo] = $this->logFailedJob();

            $failedJobs = $this->provider->all();

            $this->assertEquals([
                (object) [
                    'id' => $uuidTwo,
                    'connection' => 'connection',
                    'queue' => 'queue',
                    'payload' => json_encode(['uuid' => $uuidTwo]),
                    'exception' => (string) mb_convert_encoding((string) $exceptionTwo, 'UTF-8'),
                    'failed_at' => $failedJobs[1]->failed_at,
                    'failed_at_timestamp' => $failedJobs[1]->failed_at_timestamp,
                ],
                (object) [
                    'id' => $uuidOne,
                    'connection' => 'connection',
                    'queue' => 'queue',
                    'payload' => json_encode(['uuid' => $uuidOne]),
                    'exception' => (string) mb_convert_encoding((string) $exceptionOne, 'UTF-8'),
                    'failed_at' => $failedJobs[0]->failed_at,
                    'failed_at_timestamp' => $failedJobs[0]->failed_at_timestamp,
                ],
            ], $failedJobs);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function testCanFindFailedJobs(): void
    {
        [$uuid, $exception] = $this->logFailedJob();

        $failedJob = $this->provider->find($uuid);

        $this->assertEquals((object) [
            'id' => $uuid,
            'connection' => 'connection',
            'queue' => 'queue',
            'payload' => json_encode(['uuid' => (string) $uuid]),
            'exception' => (string) mb_convert_encoding((string) $exception, 'UTF-8'),
            'failed_at' => $failedJob->failed_at,
            'failed_at_timestamp' => $failedJob->failed_at_timestamp,
        ], $failedJob);
    }

    public function testNullIsReturnedIfJobNotFound(): void
    {
        $uuid = Str::uuid();

        $failedJob = $this->provider->find($uuid);

        $this->assertNull($failedJob);
    }

    public function testCanForgetFailedJobs(): void
    {
        [$uuid] = $this->logFailedJob();

        $this->provider->forget($uuid);

        $failedJob = $this->provider->find($uuid);

        $this->assertNull($failedJob);
    }

    public function testCanFlushFailedJobs(): void
    {
        $this->logFailedJob();
        $this->logFailedJob();

        $this->provider->flush();

        $failedJobs = $this->provider->all();

        $this->assertEmpty($failedJobs);
    }

    public function testCanPruneFailedJobs(): void
    {
        $this->logFailedJob();
        $this->logFailedJob();

        $this->provider->prune(now()->addDay(1));
        $failedJobs = $this->provider->all();
        $this->assertEmpty($failedJobs);

        $this->logFailedJob();
        $this->logFailedJob();

        $this->provider->prune(now()->subDay(1));
        $failedJobs = $this->provider->all();
        $this->assertCount(2, $failedJobs);
    }

    public function testCanPruneFailedJobsWithRelativeHours(): void
    {
        $this->logFailedJob();
        $this->logFailedJob();

        $this->provider->prune(now()->addHour(1));
        $failedJobs = $this->provider->all();
        $this->assertEmpty($failedJobs);

        $this->logFailedJob();
        $this->logFailedJob();

        $this->provider->prune(now()->subHour(1));
        $failedJobs = $this->provider->all();
        $this->assertCount(2, $failedJobs);
    }

    public function testEmptyFailedJobsByDefault(): void
    {
        $failedJobs = $this->provider->all();

        $this->assertEmpty($failedJobs);
    }

    public function testJobsCanBeCounted(): void
    {
        $this->assertSame(0, $this->provider->count());

        $this->logFailedJob('database', 'default');
        $this->assertSame(1, $this->provider->count());

        $this->logFailedJob('database', 'default');
        $this->logFailedJob('another-connection', 'another-queue');
        $this->assertSame(3, $this->provider->count());
    }

    public function testJobsCanBeCountedByConnection(): void
    {
        $this->logFailedJob('connection-1', 'default');
        $this->logFailedJob('connection-2', 'default');
        $this->assertSame(1, $this->provider->count('connection-1'));
        $this->assertSame(1, $this->provider->count('connection-2'));

        $this->logFailedJob('connection-1', 'default');
        $this->assertSame(2, $this->provider->count('connection-1'));
        $this->assertSame(1, $this->provider->count('connection-2'));
    }

    public function testJobsCanBeCountedByQueue(): void
    {
        $this->logFailedJob('database', 'queue-1');
        $this->logFailedJob('database', 'queue-2');
        $this->assertSame(1, $this->provider->count(queue: 'queue-1'));
        $this->assertSame(1, $this->provider->count(queue: 'queue-2'));

        $this->logFailedJob('database', 'queue-1');
        $this->assertSame(2, $this->provider->count(queue: 'queue-1'));
        $this->assertSame(1, $this->provider->count(queue: 'queue-2'));
    }

    public function testJobsCanBeCountedByQueueAndConnection(): void
    {
        $this->logFailedJob('connection-1', 'queue-99');
        $this->logFailedJob('connection-1', 'queue-99');
        $this->logFailedJob('connection-2', 'queue-99');
        $this->logFailedJob('connection-1', 'queue-1');
        $this->logFailedJob('connection-2', 'queue-1');
        $this->logFailedJob('connection-2', 'queue-1');
        $this->assertSame(2, $this->provider->count('connection-1', 'queue-99'));
        $this->assertSame(1, $this->provider->count('connection-2', 'queue-99'));
        $this->assertSame(1, $this->provider->count('connection-1', 'queue-1'));
        $this->assertSame(2, $this->provider->count('connection-2', 'queue-1'));
    }

    /** @return array{string, Exception} */
    public function logFailedJob(string $connection = 'connection', string $queue = 'queue'): array
    {
        $uuid = Str::uuid();

        $exception = new Exception("Something went wrong at job [{$uuid}].");

        $this->provider->log($connection, $queue, json_encode(['uuid' => (string) $uuid]), $exception);

        return [(string) $uuid, $exception];
    }
}
