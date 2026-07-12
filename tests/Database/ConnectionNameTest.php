<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\ConnectionName;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class ConnectionNameTest extends TestCase
{
    public function testParsePlainConnectionName(): void
    {
        $name = ConnectionName::parse('default');

        $this->assertSame('default', $name->requested);
        $this->assertSame('default', $name->base);
        $this->assertNull($name->role);
        $this->assertFalse($name->isRead());
        $this->assertFalse($name->isWrite());
    }

    public function testParseReadConnectionName(): void
    {
        $name = ConnectionName::parse('default::read');

        $this->assertSame('default::read', $name->requested);
        $this->assertSame('default', $name->base);
        $this->assertSame(ConnectionName::READ, $name->role);
        $this->assertTrue($name->isRead());
        $this->assertFalse($name->isWrite());
    }

    public function testParseWriteConnectionName(): void
    {
        $name = ConnectionName::parse('default::write');

        $this->assertSame('default::write', $name->requested);
        $this->assertSame('default', $name->base);
        $this->assertSame(ConnectionName::WRITE, $name->role);
        $this->assertFalse($name->isRead());
        $this->assertTrue($name->isWrite());
    }

    public function testParseRejectsDirectConnectionName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Database connection suffix [::direct] is not supported. Configure a direct connection and use migrations_connection instead.'
        );

        ConnectionName::parse('default::direct');
    }
}
