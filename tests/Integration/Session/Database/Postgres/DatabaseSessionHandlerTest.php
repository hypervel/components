<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Session\Database\Postgres;

use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Session\Database\DatabaseSessionHandlerTestCase;

#[RequiresDatabase('pgsql')]
#[WithMigration('session')]
class DatabaseSessionHandlerTest extends DatabaseSessionHandlerTestCase
{
    public function testSessionSchemaUsesTheNativeIpAddressType(): void
    {
        $this->assertSame('inet', Schema::getColumnType('sessions', 'ip_address'));
    }
}
