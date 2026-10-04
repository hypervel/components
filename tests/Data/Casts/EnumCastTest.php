<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Casts;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Data\Casts\EnumCast;
use Hypervel\Data\Casts\Uncastable;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Exceptions\CannotCastEnum;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Factories\DataPropertyFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Tests\TestCase;
use ReflectionClass;

class EnumCastTest extends TestCase
{
    public function testCanCastEnum(): void
    {
        [$properties, $context] = $this->operation();
        $property = $this->property('status');
        $cast = new EnumCast;

        $this->assertSame(EnumCastStatus::Ready, $cast->cast($property, 'ready', $properties, $context));
        $this->assertSame(EnumCastStatus::Ready, $cast->cast($property, EnumCastStatus::Ready, $properties, $context));
        $this->assertSame(EnumCastStatus::Ready, $cast->cast($property, OtherEnumCastStatus::Ready, $properties, $context));
    }

    public function testFailsWhenItCannotCastAnEnumFromValue(): void
    {
        [$properties, $context] = $this->operation();

        $this->expectException(CannotCastEnum::class);
        $this->expectExceptionMessageIsOrContains('EnumCastDataFixture::$status');

        (new EnumCast)->cast($this->property('status'), 'invalid', $properties, $context);
    }

    public function testFailsWhenCastingAnUnitEnum(): void
    {
        [$properties, $context] = $this->operation();

        $this->assertSame(
            Uncastable::create(),
            (new EnumCast)->cast($this->property('unit'), 'Ready', $properties, $context),
        );
    }

    public function testFailsWithOtherTypes(): void
    {
        [$properties, $context] = $this->operation();

        $this->assertSame(
            Uncastable::create(),
            (new EnumCast)->cast($this->property('int'), 'ready', $properties, $context),
        );
    }

    /**
     * Test integer-backed enums accept numeric strings.
     */
    public function testCastsIntegerBackedEnumFromNumericString(): void
    {
        [$properties, $context] = $this->operation();

        $this->assertSame(
            IntegerEnumCastStatus::Ready,
            (new EnumCast)->cast($this->property('integerStatus'), '1', $properties, $context),
        );
    }

    /**
     * Test iterable item enum metadata is used.
     */
    public function testCastsIterableBackedEnumValues(): void
    {
        [$properties, $context] = $this->operation();

        $this->assertSame(
            EnumCastStatus::Done,
            (new EnumCast)->castIterableItem(
                $this->property('statuses'),
                'done',
                $properties,
                $context,
            ),
        );
    }

    /**
     * Build one property definition.
     */
    protected function property(string $name): DataProperty
    {
        $defaults = require __DIR__ . '/../../../src/data/config/data.php';
        $config = new DataConfig(new Repository(['data' => $defaults]));
        $typeFactory = new DataTypeFactory(new PhpDocTypeNameResolver);
        $reflectionClass = new ReflectionClass(EnumCastDataFixture::class);

        return (new DataPropertyFactory(
            $typeFactory,
            $config,
            new NameMapperResolver(new Container),
        ))->build(
            $reflectionClass->getProperty($name),
            $reflectionClass,
            classDefinedDataIterableAnnotations: (new DataIterableAnnotationReader)->getForProperty(
                $reflectionClass->getProperty($name),
            ),
        );
    }

    /**
     * Create one cast operation.
     *
     * @return array{array<string, mixed>, CreationContext}
     */
    protected function operation(): array
    {
        return [[], new CreationContext(EnumCastDataContract::class)];
    }
}

enum EnumCastStatus: string
{
    case Ready = 'ready';
    case Done = 'done';
}

enum OtherEnumCastStatus: string
{
    case Ready = 'ready';
}

enum IntegerEnumCastStatus: int
{
    case Ready = 1;
}

enum UnitEnumCastStatus
{
    case Ready;
}

class EnumCastDataFixture
{
    public EnumCastStatus $status;

    public IntegerEnumCastStatus $integerStatus;

    public UnitEnumCastStatus $unit;

    /** @var list<EnumCastStatus> */
    public array $statuses;

    public int $int;
}

abstract class EnumCastDataContract implements BaseData
{
}
