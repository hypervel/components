<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class DatabaseTest extends TestCase
{
    #[Test]
    public function testbenchDoesntAutomaticallyCreateDatabaseConnection(): void
    {
        $this->assertCount(0, DB::getConnections());
    }
}
