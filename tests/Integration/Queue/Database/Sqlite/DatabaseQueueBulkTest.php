<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database\Sqlite;

use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Queue\Fixtures\IntegerQueueName;

#[RequiresDatabase('sqlite')]
#[WithConfig('queue.default', 'database')]
#[WithMigration('hypervel', 'queue')]
class DatabaseQueueBulkTest extends TestCase
{
    use DatabaseMigrations;

    public function testOversizedBulkInsertIsChunkedAndCommittedCompletely(): void
    {
        $insertCount = 0;

        DB::listen(static function (QueryExecuted $query) use (&$insertCount): void {
            $sql = strtolower($query->sql);

            if (str_starts_with($sql, 'insert into') && str_contains($sql, 'jobs')) {
                ++$insertCount;
            }
        });

        // Six bindings per job put this batch just above SQLite's 32,766-binding limit.
        $jobs = array_map(static fn (int $index): string => "job-{$index}", range(1, 5462));

        $this->assertTrue(Queue::connection()->bulk($jobs, queue: IntegerQueueName::Zero));
        $this->assertSame(5462, DB::table('jobs')->where('queue', '0')->count());
        $this->assertSame(2, $insertCount);
    }
}
