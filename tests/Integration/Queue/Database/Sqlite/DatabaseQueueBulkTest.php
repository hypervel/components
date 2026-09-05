<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database\Sqlite;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;

#[RequiresDatabase('sqlite')]
#[WithConfig('queue.default', 'database')]
#[WithMigration('hypervel', 'queue')]
class DatabaseQueueBulkTest extends TestCase
{
    use DatabaseMigrations;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $connection = $config->string('database.default');
        $config->set("database.connections.{$connection}.version", '3.31.1');
    }

    public function testOversizedBulkInsertIsChunkedAndCommittedCompletely(): void
    {
        $insertCount = 0;

        DB::listen(static function (QueryExecuted $query) use (&$insertCount): void {
            $sql = strtolower($query->sql);

            if (str_starts_with($sql, 'insert into') && str_contains($sql, 'jobs')) {
                ++$insertCount;
            }
        });

        $jobs = array_map(static fn (int $index): string => "job-{$index}", range(1, 167));

        $this->assertTrue(Queue::connection()->bulk($jobs, queue: 'bulk'));
        $this->assertSame(167, DB::table('jobs')->where('queue', 'bulk')->count());
        $this->assertSame(2, $insertCount);
    }
}
