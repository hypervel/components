<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Testbench\TestCase;

enum ModelTestStringBackedConnection: string
{
    case Default = 'default';
    case Testing = 'testing';
}

enum ModelTestIntBackedConnection: int
{
    case Default = 1;
    case Testing = 2;
}

enum ModelTestUnitConnection
{
    case default;
    case testing;
}

/**
 * @internal
 * @coversNothing
 */
class ModelEnumTest extends TestCase
{
    public function testSetConnectionAcceptsStringBackedEnum(): void
    {
        $model = new ModelEnumTestModel();
        $model->setConnection(ModelTestStringBackedConnection::Testing);

        $this->assertSame('testing', $model->getConnectionName());
    }

    public function testSetConnectionAcceptsIntBackedEnum(): void
    {
        $model = new ModelEnumTestModel();
        $model->setConnection(ModelTestIntBackedConnection::Testing);

        $this->assertSame('2', $model->getConnectionName());
    }

    public function testSetConnectionAcceptsUnitEnum(): void
    {
        $model = new ModelEnumTestModel();
        $model->setConnection(ModelTestUnitConnection::testing);

        $this->assertSame('testing', $model->getConnectionName());
    }

    public function testSetConnectionAcceptsString(): void
    {
        $model = new ModelEnumTestModel();
        $model->setConnection('mysql');

        $this->assertSame('mysql', $model->getConnectionName());
    }

    public function testSetConnectionAcceptsNull(): void
    {
        $model = new ModelEnumTestModel();
        $model->setConnection(null);

        $this->assertNull($model->getConnectionName());
    }
}

class ModelEnumTestModel extends Model
{
    protected ?string $table = 'test_models';
}
