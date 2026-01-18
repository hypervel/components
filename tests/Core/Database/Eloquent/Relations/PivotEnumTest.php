<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Relations;

use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Testbench\TestCase;

enum PivotTestStringBackedConnection: string
{
    case Default = 'default';
    case Testing = 'testing';
}

enum PivotTestIntBackedConnection: int
{
    case Default = 1;
    case Testing = 2;
}

enum PivotTestUnitConnection
{
    case default;
    case testing;
}

/**
 * @internal
 * @coversNothing
 */
class PivotEnumTest extends TestCase
{
    public function testSetConnectionAcceptsStringBackedEnum(): void
    {
        $pivot = new Pivot();
        $pivot->setConnection(PivotTestStringBackedConnection::Testing);

        $this->assertSame('testing', $pivot->getConnectionName());
    }

    public function testSetConnectionAcceptsIntBackedEnum(): void
    {
        $pivot = new Pivot();
        $pivot->setConnection(PivotTestIntBackedConnection::Testing);

        $this->assertSame('2', $pivot->getConnectionName());
    }

    public function testSetConnectionAcceptsUnitEnum(): void
    {
        $pivot = new Pivot();
        $pivot->setConnection(PivotTestUnitConnection::testing);

        $this->assertSame('testing', $pivot->getConnectionName());
    }

    public function testSetConnectionAcceptsString(): void
    {
        $pivot = new Pivot();
        $pivot->setConnection('mysql');

        $this->assertSame('mysql', $pivot->getConnectionName());
    }

    public function testSetConnectionAcceptsNull(): void
    {
        $pivot = new Pivot();
        $pivot->setConnection(null);

        $this->assertNull($pivot->getConnectionName());
    }
}
