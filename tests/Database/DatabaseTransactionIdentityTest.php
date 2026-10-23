<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Tests\TestCase;
use PDO;

class DatabaseTransactionIdentityTest extends TestCase
{
    public function testRoleTransactionsKeepIndependentCallbacksAndSavepoints(): void
    {
        [$base, $writer] = $this->connections();
        $callbacks = [];

        $base->beginTransaction();
        $writer->beginTransaction();
        $base->afterCommit(function () use (&$callbacks): void {
            $callbacks[] = 'base committed';
        });
        $writer->afterRollBack(function () use (&$callbacks): void {
            $callbacks[] = 'writer rolled back';
        });

        $writer->transaction(function () use ($writer, &$callbacks): void {
            $writer->afterCommit(function () use (&$callbacks): void {
                $callbacks[] = 'writer committed';
            });
        });

        $base->commit();

        $this->assertSame(['base committed'], $callbacks);
        $this->assertTrue($writer->hasApplicableTransaction());
        $this->assertFalse($base->hasApplicableTransaction());

        $writer->rollBack();

        $this->assertSame(['base committed', 'writer rolled back'], $callbacks);
        $this->assertTrue($writer->getTransactionManager()->getPendingTransactions()->isEmpty());
        $this->assertTrue($writer->getTransactionManager()->getCommittedTransactions()->isEmpty());
    }

    public function testRollingBackASavepointOnlyReleasesItsOwnersCallbacks(): void
    {
        [$base, $writer] = $this->connections();
        $callbacks = [];

        $base->beginTransaction();
        $writer->beginTransaction();
        $writer->beginTransaction();
        $writer->afterRollBack(function () use (&$callbacks): void {
            $callbacks[] = 'savepoint rolled back';
        });
        $base->afterCommit(function () use (&$callbacks): void {
            $callbacks[] = 'base committed';
        });
        $writer->rollBack();

        $this->assertSame(['savepoint rolled back'], $callbacks);

        $writer->afterCommit(function () use (&$callbacks): void {
            $callbacks[] = 'writer committed';
        });
        $writer->commit();
        $this->assertSame(['savepoint rolled back', 'writer committed'], $callbacks);

        $base->commit();
        $this->assertSame(['savepoint rolled back', 'writer committed', 'base committed'], $callbacks);
    }

    public function testDisconnectOnlyResetsItsOwnersTransactionRecords(): void
    {
        [$base, $writer] = $this->connections();
        $callbacks = [];

        $base->beginTransaction();
        $writer->beginTransaction();
        $base->afterRollBack(function () use (&$callbacks): void {
            $callbacks[] = 'base rolled back';
        });
        $writer->afterRollBack(function () use (&$callbacks): void {
            $callbacks[] = 'writer rolled back';
        });

        $writer->disconnect();
        $this->assertSame(['writer rolled back'], $callbacks);
        $this->assertTrue($base->hasApplicableTransaction());

        $base->rollBack();
        $this->assertSame(['writer rolled back', 'base rolled back'], $callbacks);
    }

    /**
     * Create independent sessions for the same configured connection name.
     *
     * @return array{SQLiteConnection, SQLiteConnection}
     */
    protected function connections(): array
    {
        $manager = new DatabaseTransactionsManager;
        $base = new SQLiteConnection(new PDO('sqlite::memory:'), config: ['name' => 'testing']);
        $writer = new SQLiteConnection(new PDO('sqlite::memory:'), config: ['name' => 'testing']);
        $writer->setReadWriteType('write');
        $base->setTransactionManager($manager);
        $writer->setTransactionManager($manager);

        return [$base, $writer];
    }
}
