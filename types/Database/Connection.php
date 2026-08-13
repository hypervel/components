<?php

declare(strict_types=1);

namespace Hypervel\Types\Database\Connection;

use Hypervel\Database\ConnectionInterface;
use Hypervel\Support\Facades\DB;

use function PHPStan\Testing\assertType;

function testTransactionLevelIsImpure(ConnectionInterface $connection): void
{
    if ($connection->transactionLevel() === 0) {
        assertType('int', $connection->transactionLevel());
    }

    if (DB::transactionLevel() === 0) {
        assertType('int', DB::transactionLevel());
    }
}

function testWithoutTablePrefixPreservesCallbackReturn(ConnectionInterface $connection): void
{
    assertType("'preserved'", $connection->withoutTablePrefix(fn () => 'preserved'));
}

function testCursorRowsAreMixed(ConnectionInterface $connection): void
{
    foreach ($connection->cursor('select 1') as $key => $row) {
        assertType('int', $key);
        assertType('mixed', $row);
    }
}
