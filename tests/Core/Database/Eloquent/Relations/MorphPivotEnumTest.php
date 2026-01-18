<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Relations;

use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Testbench\TestCase;

enum MorphPivotTestStringBackedConnection: string
{
    case Default = 'default';
    case Testing = 'testing';
}

enum MorphPivotTestIntBackedConnection: int
{
    case Default = 1;
    case Testing = 2;
}

enum MorphPivotTestUnitConnection
{
    case default;
    case testing;
}

/**
 * @internal
 * @coversNothing
 */
class MorphPivotEnumTest extends TestCase
{
    public function testSetConnectionAcceptsStringBackedEnum(): void
    {
        $pivot = new MorphPivot();
        $pivot->setConnection(MorphPivotTestStringBackedConnection::Testing);

        $this->assertSame('testing', $pivot->getConnectionName());
    }

    public function testSetConnectionAcceptsIntBackedEnum(): void
    {
        $pivot = new MorphPivot();
        $pivot->setConnection(MorphPivotTestIntBackedConnection::Testing);

        $this->assertSame('2', $pivot->getConnectionName());
    }

    public function testSetConnectionAcceptsUnitEnum(): void
    {
        $pivot = new MorphPivot();
        $pivot->setConnection(MorphPivotTestUnitConnection::testing);

        $this->assertSame('testing', $pivot->getConnectionName());
    }

    public function testSetConnectionAcceptsString(): void
    {
        $pivot = new MorphPivot();
        $pivot->setConnection('mysql');

        $this->assertSame('mysql', $pivot->getConnectionName());
    }

    public function testSetConnectionAcceptsNull(): void
    {
        $pivot = new MorphPivot();
        $pivot->setConnection(null);

        $this->assertNull($pivot->getConnectionName());
    }
}
