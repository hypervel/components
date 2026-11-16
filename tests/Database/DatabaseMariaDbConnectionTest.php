<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\MariaDbConnection;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class DatabaseMariaDbConnectionTest extends TestCase
{
    use VerifiesDoubles;

    public function testWaitingForACommitIdWaitsOnlyForTheMissingDomains(): void
    {
        $connection = Double::for(MariaDbConnection::class)->passthru();
        $connection->expects('selectOne')
            ->with('select master_gtid_wait(?, 0) as master_gtid_wait, @@gtid_current_pos as gtid_current_pos', ['0-1-5,1-2-9'])
            ->returns((object) ['master_gtid_wait' => -1, 'gtid_current_pos' => '0-1-5,1-2-7']);

        // Domain 0 holds this server's own write, which MASTER_GTID_WAIT never sees.
        $connection->expects('scalar')
            ->with('select master_gtid_wait(?, ?)', ['1-2-9', 5])
            ->returns(0);

        $this->assertTrue($connection->hasCommitId('0-1-5,1-2-9', 5));
    }
}
