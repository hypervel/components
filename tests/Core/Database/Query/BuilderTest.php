<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Query;

use Hypervel\Database\Query\Builder;
use Mockery as m;
use PHPUnit\Framework\TestCase;

enum BuilderTestStringEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

enum BuilderTestIntEnum: int
{
    case One = 1;
    case Two = 2;
}

enum BuilderTestUnitEnum
{
    case Published;
    case Draft;
}

/**
 * @internal
 * @coversNothing
 */
class BuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();

        parent::tearDown();
    }

    public function testCastBindingWithStringBackedEnum(): void
    {
        $builder = $this->getBuilder();

        $result = $builder->castBinding(BuilderTestStringEnum::Active);

        $this->assertSame('active', $result);
    }

    public function testCastBindingWithIntBackedEnum(): void
    {
        $builder = $this->getBuilder();

        $result = $builder->castBinding(BuilderTestIntEnum::Two);

        $this->assertSame(2, $result);
    }

    public function testCastBindingWithUnitEnum(): void
    {
        $builder = $this->getBuilder();

        $result = $builder->castBinding(BuilderTestUnitEnum::Published);

        // UnitEnum uses ->name via enum_value()
        $this->assertSame('Published', $result);
    }

    public function testCastBindingWithString(): void
    {
        $builder = $this->getBuilder();

        $result = $builder->castBinding('test');

        $this->assertSame('test', $result);
    }

    public function testCastBindingWithInt(): void
    {
        $builder = $this->getBuilder();

        $result = $builder->castBinding(42);

        $this->assertSame(42, $result);
    }

    public function testCastBindingWithNull(): void
    {
        $builder = $this->getBuilder();

        $result = $builder->castBinding(null);

        $this->assertNull($result);
    }

    protected function getBuilder(): Builder
    {
        $grammar = m::mock(\Hyperf\Database\Query\Grammars\Grammar::class);
        $processor = m::mock(\Hyperf\Database\Query\Processors\Processor::class);
        $connection = m::mock(\Hyperf\Database\ConnectionInterface::class);

        $connection->shouldReceive('getQueryGrammar')->andReturn($grammar);
        $connection->shouldReceive('getPostProcessor')->andReturn($processor);

        return new Builder($connection);
    }
}
