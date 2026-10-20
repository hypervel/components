<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Pennant\Database\MariaDb;

use Hypervel\Database\QueryException;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Pennant\Database\MySqlFamilyDatabaseDriverTestCase;

#[RequiresDatabase('mariadb')]
class DatabaseDriverTest extends MySqlFamilyDatabaseDriverTestCase
{
    public function testConcurrentWinnerIsReturnedInsideARepeatableReadTransactionWithoutSnapshotIsolation(): void
    {
        Feature::define('foo', fn () => 'candidate');
        DB::statement('set session transaction isolation level repeatable read');

        // Releases without the setting behave as if it were off.
        if ($this->hasSnapshotIsolationSetting()) {
            DB::statement('set session innodb_snapshot_isolation = off');
        }

        $this->storeWinnerBeforeInsert('foo', 'tim', 'winner');

        $this->assertSame('winner', DB::transaction(fn () => Feature::for('tim')->value('foo')));
    }

    public function testSnapshotConflictsInsideARepeatableReadTransactionPropagate(): void
    {
        if (! $this->hasSnapshotIsolationSetting()) {
            $this->markTestSkipped('This MariaDB release has no innodb_snapshot_isolation setting.');
        }

        Feature::define('foo', fn () => 'candidate');
        DB::statement('set session transaction isolation level repeatable read');
        DB::statement('set session innodb_snapshot_isolation = on');
        $this->storeWinnerBeforeInsert('foo', 'tim', 'winner');

        try {
            DB::transaction(fn () => Feature::for('tim')->value('foo'));
            $this->fail('The insert should have conflicted with the transaction snapshot.');
        } catch (QueryException $exception) {
            $this->assertSame(1020, $exception->errorInfo[1]);
        }
    }

    /**
     * Determine whether the server has the innodb_snapshot_isolation setting.
     */
    protected function hasSnapshotIsolationSetting(): bool
    {
        return DB::selectOne("show variables like 'innodb_snapshot_isolation'") !== null;
    }
}
