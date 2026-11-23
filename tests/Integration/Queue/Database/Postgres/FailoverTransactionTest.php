<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database\Postgres;

use Hypervel\Database\QueryException;
use Hypervel\Queue\Events\JobQueueing;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

#[RequiresDatabase('pgsql')]
class FailoverTransactionTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testFailoverOnlyContinuesWhenTheBusinessTransactionCanCommit(bool $recover): void
    {
        $database = config('queue.connections.database');
        config([
            'queue.connections.primary' => ['after_commit' => false] + $database,
            'queue.connections.fallback' => ['after_commit' => false, 'table' => 'pg_temp.fallback_jobs'] + $database,
            'queue.connections.failover.connections' => ['primary', 'fallback'],
            'queue.connections.failover.after_commit' => false,
        ]);

        DB::statement('create temporary table business_writes (id integer)');
        DB::statement('create temporary table fallback_jobs (id serial primary key, queue text, payload text, attempts integer, reserved_at integer, available_at integer, created_at integer)');
        $attempts = [];
        Event::listen(JobQueueing::class, static function (JobQueueing $event) use ($recover, &$attempts): void {
            $attempts[] = $event->connectionName;
            if ($event->connectionName === 'primary') {
                $query = static fn () => DB::table('pg_temp.missing_queue')->count();
                $recover ? DB::transaction($query) : $query();
            }
        });

        try {
            DB::transaction(static function (): void {
                DB::table('pg_temp.business_writes')->insert(['id' => 1]);
                Queue::connection('failover')->push('ExampleJob');
            });
            $this->assertTrue($recover);
        } catch (QueryException $exception) {
            $this->assertFalse($recover);
            $this->assertSame('42P01', $exception->getCode());
        }

        $this->assertSame($recover ? ['primary', 'fallback'] : ['primary'], $attempts);
        $this->assertSame((int) $recover, DB::table('pg_temp.business_writes')->count());
        $this->assertSame((int) $recover, DB::table('pg_temp.fallback_jobs')->count());
    }
}
