<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Queue\Failed\DatabaseUuidFailedJobProvider;
use Hypervel\Support\Carbon;
use Hypervel\Support\Str;
use Hypervel\Testbench\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class DatabaseUuidFailedJobProviderTest extends TestCase
{
    use RefreshDatabase;

    protected ?DatabaseUuidFailedJobProvider $provider = null;

    protected ?ConnectionResolverInterface $resolver = null;

    protected bool $migrateRefresh = true;

    public function setUp(): void
    {
        parent::setUp();

        $this->provider = new DatabaseUuidFailedJobProvider(
            $this->resolver = $this->app->make(ConnectionResolverInterface::class),
            'failed_jobs'
        );
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    public function testGettingIdsOfAllFailedJobs()
    {
        $this->provider->log('connection-1', 'queue-1', json_encode(['uuid' => 'uuid-1']), new RuntimeException());
        $this->provider->log('connection-1', 'queue-1', json_encode(['uuid' => 'uuid-2']), new RuntimeException());
        $this->provider->log('connection-2', 'queue-2', json_encode(['uuid' => 'uuid-3']), new RuntimeException());
        $this->provider->log('connection-2', 'queue-2', json_encode(['uuid' => 'uuid-4']), new RuntimeException());

        $this->assertSame(['uuid-4', 'uuid-3', 'uuid-2', 'uuid-1'], $this->provider->ids());
        $this->assertSame(['uuid-2', 'uuid-1'], $this->provider->ids('queue-1'));
        $this->assertSame(['uuid-4', 'uuid-3'], $this->provider->ids('queue-2'));
    }

    public function testGettingAllFailedJobs()
    {
        $this->assertEmpty($this->provider->all());

        $this->provider->log('connection-1', 'queue-1', json_encode(['uuid' => 'uuid-1']), new RuntimeException());
        $this->provider->log('connection-1', 'queue-1', json_encode(['uuid' => 'uuid-2']), new RuntimeException());
        $this->provider->log('connection-2', 'queue-2', json_encode(['uuid' => 'uuid-3']), new RuntimeException());
        $this->provider->log('connection-2', 'queue-2', json_encode(['uuid' => 'uuid-4']), new RuntimeException());

        $this->assertCount(4, $this->provider->all());

        $this->assertSame(
            ['uuid-4', 'uuid-3', 'uuid-2', 'uuid-1'],
            array_column($this->provider->all(), 'id')
        );
    }

    public function testFindingFailedJobsById()
    {
        $this->provider->log('connection-1', 'queue-1', json_encode(['uuid' => 'uuid-1']), new RuntimeException());

        $this->assertNull($this->provider->find('uuid-2'));
        $this->assertEquals('uuid-1', $this->provider->find('uuid-1')->id);
        $this->assertEquals('queue-1', $this->provider->find('uuid-1')->queue);
        $this->assertEquals('connection-1', $this->provider->find('uuid-1')->connection);
    }

    public function testRemovingJobsById()
    {
        $this->provider->log('connection-1', 'queue-1', json_encode(['uuid' => 'uuid-1']), new RuntimeException());

        $this->assertNotNull($this->provider->find('uuid-1'));

        $this->provider->forget('uuid-1');

        $this->assertNull($this->provider->find('uuid-1'));
    }

    public function testRemovingAllFailedJobs()
    {
        $this->provider->log('connection-1', 'queue-1', json_encode(['uuid' => 'uuid-1']), new RuntimeException());
        $this->provider->log('connection-2', 'queue-2', json_encode(['uuid' => 'uuid-2']), new RuntimeException());

        $this->assertCount(2, $this->provider->all());

        $this->provider->flush();

        $this->assertEmpty($this->provider->all());
    }

    public function testPruningFailedJobs()
    {
        Carbon::setTestNow(Carbon::createFromDate(2024, 4, 28));

        $this->provider->log('connection-1', 'queue-1', json_encode(['uuid' => 'uuid-1']), new RuntimeException());
        $this->provider->log('connection-2', 'queue-2', json_encode(['uuid' => 'uuid-2']), new RuntimeException());

        $this->provider->prune(Carbon::createFromDate(2024, 4, 26));

        $this->assertCount(2, $this->provider->all());

        $this->provider->prune(Carbon::createFromDate(2024, 4, 30));

        $this->assertEmpty($this->provider->all());
    }

    public function testJobsCanBeCounted()
    {
        $this->assertSame(0, $this->provider->count());

        $this->provider->log('connection-1', 'queue-1', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->assertSame(1, $this->provider->count());

        $this->provider->log('connection-1', 'queue-1', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->provider->log('connection-2', 'queue-2', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->assertSame(3, $this->provider->count());
    }

    public function testJobsCanBeCountedByConnection()
    {
        $this->provider->log('connection-1', 'default', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->provider->log('connection-2', 'default', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->assertSame(1, $this->provider->count('connection-1'));
        $this->assertSame(1, $this->provider->count('connection-2'));

        $this->provider->log('connection-1', 'default', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->assertSame(2, $this->provider->count('connection-1'));
        $this->assertSame(1, $this->provider->count('connection-2'));
    }

    public function testJobsCanBeCountedByQueue()
    {
        $this->provider->log('database', 'queue-1', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->provider->log('database', 'queue-2', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->assertSame(1, $this->provider->count(queue: 'queue-1'));
        $this->assertSame(1, $this->provider->count(queue: 'queue-2'));

        $this->provider->log('database', 'queue-1', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->assertSame(2, $this->provider->count(queue: 'queue-1'));
        $this->assertSame(1, $this->provider->count(queue: 'queue-2'));
    }

    public function testJobsCanBeCountedByQueueAndConnection()
    {
        $this->provider->log('connection-1', 'queue-99', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->provider->log('connection-1', 'queue-99', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->provider->log('connection-2', 'queue-99', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->provider->log('connection-1', 'queue-1', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->provider->log('connection-2', 'queue-1', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->provider->log('connection-2', 'queue-1', json_encode(['uuid' => (string) Str::uuid()]), new RuntimeException());
        $this->assertSame(2, $this->provider->count('connection-1', 'queue-99'));
        $this->assertSame(1, $this->provider->count('connection-2', 'queue-99'));
        $this->assertSame(1, $this->provider->count('connection-1', 'queue-1'));
        $this->assertSame(2, $this->provider->count('connection-2', 'queue-1'));
    }
}
