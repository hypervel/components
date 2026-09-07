<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hypervel\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Hypervel\Support\Str;
use PDOException;
use Throwable;

class ConcurrencyErrorDetector implements ConcurrencyErrorDetectorContract
{
    /**
     * Determine if the given exception was caused by a concurrency error such as a deadlock or serialization failure.
     */
    public function causedByConcurrencyError(Throwable $e): bool
    {
        if ($e instanceof PDOException) {
            if (in_array($e->getCode(), [40001, '40001', '40P01', '55P03'], true)) {
                return true;
            }

            // These are exact SQLite and MySQL-family driver codes. PostgreSQL
            // concurrency failures are identified by their SQLSTATE above.
            if (in_array($e->errorInfo[1] ?? null, [5, 6, 1205], true)) {
                return true;
            }
        }

        $message = $e->getMessage();

        return Str::contains($message, [
            'Deadlock found when trying to get lock',
            'deadlock detected',
            'The database file is locked',
            'database is locked',
            'database table is locked',
            'A table in the database is locked',
            'has been chosen as the deadlock victim',
            'Lock wait timeout exceeded; try restarting transaction',
            'WSREP detected deadlock/conflict and aborted the transaction. Try restarting the transaction',
            'Record has changed since last read in table',
        ]);
    }
}
