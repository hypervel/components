<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel;

use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class ConnectionThreadsCountTest extends DatabaseTestCase
{
    public function testGetThreadsCount()
    {
        $count = DB::connection()->threadCount();

        if ($this->driver === 'sqlite') {
            $this->assertNull($count, 'SQLite does not support connection count');
        } else {
            $this->assertGreaterThanOrEqual(1, $count);
        }
    }
}
