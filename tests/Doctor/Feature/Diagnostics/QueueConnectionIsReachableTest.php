<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Doctor\Diagnostics\QueueConnectionIsReachable;
use Hypervel\Doctor\Results\Link;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Doctor\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class QueueConnectionIsReachableTest extends TestCase
{
    /**
     * Use an isolated in-memory database for schema probes.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('database.default', 'sqlite');
        $app->make('config')->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    public function testReportsAMissingDatabaseQueueTable(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => null,
                'table' => 'doctor_jobs',
            ],
        ]);

        Schema::dropIfExists('doctor_jobs');

        $result = (new QueueConnectionIsReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('doctor_jobs', $result->details);
        $this->assertEquals([Link::docs('queues')], $result->links);
    }

    public function testDoesNotInventAMissingDatabaseQueueTableSetting(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database']]);

        $result = (new QueueConnectionIsReachable)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('database queue table is not configured', $result->details);
    }

    #[DataProvider('failoverConnections')]
    public function testChecksFailoverCapacity(array $children, string $status): void
    {
        config([
            'queue.default' => 'main',
            'queue.connections' => [
                'main' => ['driver' => 'failover', 'connections' => $children],
                'healthy' => ['driver' => 'sync'],
                'broken' => ['driver' => 'unsupported'],
                'nested' => ['driver' => 'failover', 'connections' => ['healthy', 'broken', 'broken']],
            ],
        ]);

        $result = (new QueueConnectionIsReachable)->check();

        $this->assertSame($status, $result->status->value, $result->details ?? $result->summary);

        if (in_array('broken', $children, true) || in_array('nested', $children, true)) {
            $this->assertSame(1, substr_count($result->details, '[broken]'));
        }
    }

    /**
     * Provide healthy, degraded, failed, and invalid failover graphs.
     */
    public static function failoverConnections(): array
    {
        return [
            'healthy' => [['healthy'], 'pass'],
            'dead primary' => [['broken', 'healthy'], 'warn'],
            'dead backup' => [['healthy', 'broken'], 'warn'],
            'all unavailable' => [['broken', 'missing'], 'fail'],
            'nested and repeated' => [['nested', 'healthy'], 'warn'],
            'empty' => [[], 'fail'],
            'cycle beside healthy' => [['main', 'healthy'], 'fail'],
            'implicit null child' => [['null'], 'pass'],
        ];
    }

    public function testBuiltInNullConnectionNeedsNoConfigurationRecord(): void
    {
        config(['queue.default' => 'null', 'queue.connections' => []]);

        $this->assertSame('pass', (new QueueConnectionIsReachable)->check()->status->value);
    }
}
