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
use Hypervel\Data\Support\Creation\ConstructionState;
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
    /**
     * Test declared backed enum values are cast or passed through.
     */
    public function testCastsDeclaredBackedEnumValues(): void
    {
        [$state, $context] = $this->operation();
        $property = $this->property('status');
        $cast = new EnumCast;

        $this->assertSame(EnumCastStatus::Ready, $cast->cast($property, 'ready', $state, $context));
        $this->assertSame(EnumCastStatus::Ready, $cast->cast($property, EnumCastStatus::Ready, $state, $context));
        $this->assertSame(EnumCastStatus::Ready, $cast->cast($property, OtherEnumCastStatus::Ready, $state, $context));
    }

    /**
     * Test iterable item enum metadata is used.
     */
    public function testCastsIterableBackedEnumValues(): void
    {
        [$state, $context] = $this->operation();

        $this->assertSame(
            EnumCastStatus::Done,
            (new EnumCast)->castIterableItem(
                $this->property('statuses'),
                'done',
                $state,
                $context,
            ),
        );
    }

    /**
     * Test a declaration without a backed enum declines the cast.
     */
    public function testReturnsUncastableWithoutABackedEnumDeclaration(): void
    {
        [$state, $context] = $this->operation();

        $this->assertSame(
            Uncastable::create(),
            (new EnumCast)->cast($this->property('name'), 'ready', $state, $context),
        );
    }

    /**
     * Test invalid enum values produce a property-specific exception.
     */
    public function testThrowsForAnInvalidBackedEnumValue(): void
    {
        [$state, $context] = $this->operation();

        $this->expectException(CannotCastEnum::class);
        $this->expectExceptionMessageIsOrContains('EnumCastDataFixture::$status');

        (new EnumCast)->cast($this->property('status'), 'invalid', $state, $context);
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
     * @return array{ConstructionState, CreationContext}
     */
    protected function operation(): array
    {
        $context = new CreationContext(EnumCastDataContract::class);

        return [ConstructionState::create($context, EnumCastDataContract::class), $context];
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

class EnumCastDataFixture
{
    public EnumCastStatus $status;

    /** @var list<EnumCastStatus> */
    public array $statuses;

    public string $name;
}

abstract class EnumCastDataContract implements BaseData
{
}
