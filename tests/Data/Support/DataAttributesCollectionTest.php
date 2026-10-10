<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\DataAttributesCollectionTest;

use Attribute;
use Hypervel\Data\Attributes\AutoClosureLazy;
use Hypervel\Data\Attributes\AutoLazy;
use Hypervel\Data\Attributes\GetsCast;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Mappers\CamelCaseMapper;
use Hypervel\Data\Support\Factories\DataAttributesCollectionFactory;
use Hypervel\Tests\Data\Fixtures\Casts\ConfidentialDataCast;
use Hypervel\Tests\TestCase;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

class DataAttributesCollectionTest extends TestCase
{
    // The collection holds attribute recipes, which create the attribute on newInstance(), where Spatie's holds
    // the attribute objects.

    public function testCanGetTheAttributesFromAReflectionClass(): void
    {
        $attributes = DataAttributesCollectionFactory::buildFromReflectionClass(
            new ReflectionClass(TestClassWithAttribute::class)
        );

        $this->assertTrue($attributes->has(MapInputName::class));
        $this->assertInstanceOf(MapInputName::class, $attributes->first(MapInputName::class)?->newInstance());
    }

    public function testCanGetAttributesFromAReflectionClassAndItsParents(): void
    {
        $attributes = DataAttributesCollectionFactory::buildFromReflectionClass(
            new ReflectionClass(TestInheritedClassWithAttribute::class)
        );

        $this->assertTrue($attributes->has(MapInputName::class));
        $this->assertInstanceOf(MapInputName::class, $attributes->first(MapInputName::class)?->newInstance());

        $this->assertTrue($attributes->has(MapOutputName::class));
        $this->assertInstanceOf(MapOutputName::class, $attributes->first(MapOutputName::class)?->newInstance());
    }

    public function testCanGetAttributesForAReflectionProperty(): void
    {
        $class = new class {
            #[MapInputName(CamelCaseMapper::class)]
            protected string $first_name;
        };

        $attributes = DataAttributesCollectionFactory::buildFromReflectionProperty(
            new ReflectionProperty($class, 'first_name')
        );

        $this->assertTrue($attributes->has(MapInputName::class));
        $this->assertInstanceOf(MapInputName::class, $attributes->first(MapInputName::class)?->newInstance());
    }

    public function testCanGetMultipleVersionsOfAnAttributeAttributesForAReflectionProperty(): void
    {
        $class = new class {
            #[RepeatableAttribute('a')]
            #[RepeatableAttribute('b')]
            protected string $first_name;
        };

        $attributes = DataAttributesCollectionFactory::buildFromReflectionProperty(
            new ReflectionProperty($class, 'first_name')
        );

        $this->assertTrue($attributes->has(RepeatableAttribute::class));
        $this->assertEquals(
            [new RepeatableAttribute('a'), new RepeatableAttribute('b')],
            array_map(fn (ReflectionAttribute $attribute): object => $attribute->newInstance(), $attributes->all(RepeatableAttribute::class)),
        );
    }

    public function testCanGetTheAttributeByItsParentClass(): void
    {
        $class = new class {
            #[AutoClosureLazy]
            protected string $first_name;
        };

        $attributes = DataAttributesCollectionFactory::buildFromReflectionProperty(
            new ReflectionProperty($class, 'first_name')
        );

        $this->assertTrue($attributes->has(AutoLazy::class));
        $this->assertInstanceOf(AutoClosureLazy::class, $attributes->first(AutoLazy::class)?->newInstance());
        $this->assertSame($attributes->first(AutoClosureLazy::class), $attributes->first(AutoLazy::class));
    }

    public function testCanGetTheAttributeByItsInterface(): void
    {
        $class = new class {
            #[WithCast(ConfidentialDataCast::class)]
            protected string $first_name;
        };

        $attributes = DataAttributesCollectionFactory::buildFromReflectionProperty(
            new ReflectionProperty($class, 'first_name')
        );

        $this->assertTrue($attributes->has(GetsCast::class));
        $this->assertInstanceOf(WithCast::class, $attributes->first(GetsCast::class)?->newInstance());
        $this->assertSame($attributes->first(WithCast::class), $attributes->first(GetsCast::class));
    }

    /**
     * Test that attribute constructors remain lazy recipes.
     */
    public function testAttributeConstructorsAreNotRunWhileMetadataIsGrouped(): void
    {
        $attributes = DataAttributesCollectionFactory::buildFromReflectionClass(
            new ReflectionClass(DataAttributesClassWithThrowingAttribute::class),
        );

        $this->assertTrue($attributes->has(DataAttributesThrowingAttribute::class));

        $this->expectException(RuntimeException::class);

        $attributes->first(DataAttributesThrowingAttribute::class)?->newInstance();
    }

    /**
     * Test that child class recipes take precedence over inherited recipes.
     */
    public function testClassAttributesIncludeParentsInChildFirstOrder(): void
    {
        $attributes = DataAttributesCollectionFactory::buildFromReflectionClass(
            new ReflectionClass(DataAttributesChildFixture::class),
        );

        $this->assertSame(['child', 'parent'], array_map(
            fn ($attribute): string => $attribute->newInstance()->name,
            $attributes->all(DataAttributesConcreteAttribute::class),
        ));
    }

    /**
     * Test that unknown class attributes are ignored safely.
     */
    public function testUnknownAttributesAreIgnored(): void
    {
        $attributes = DataAttributesCollectionFactory::buildFromReflectionClass(
            new ReflectionClass(DataAttributesUnknownAttributeFixture::class),
        );

        $this->assertFalse($attributes->has('Hypervel\Tests\Data\Support\DataAttributesCollectionTest\MissingAttribute'));
    }

    /**
     * Test that parameter attributes retain fresh object arguments.
     */
    public function testParameterAttributeArgumentsAreRecreatedForEachInstantiation(): void
    {
        $parameter = (new ReflectionMethod(DataAttributesParameterFixture::class, '__construct'))
            ->getParameters()[0];
        $attributes = DataAttributesCollectionFactory::buildFromReflectionParameter($parameter);
        $recipe = $attributes->first(DataAttributesObjectAttribute::class);

        $this->assertNotNull($recipe);

        $first = $recipe->newInstance();
        $second = $recipe->newInstance();

        $this->assertEquals($first, $second);
        $this->assertNotSame($first, $second);
        $this->assertNotSame($first->value, $second->value);
    }
}

#[MapInputName(CamelCaseMapper::class)]
class TestClassWithAttribute
{
}

#[MapOutputName(CamelCaseMapper::class)]
class TestInheritedClassWithAttribute extends TestClassWithAttribute
{
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class RepeatableAttribute
{
    /**
     * Create a repeatable test attribute.
     */
    public function __construct(public string $name)
    {
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
class DataAttributesConcreteAttribute
{
    /**
     * Create a new concrete test attribute.
     */
    public function __construct(public readonly string $name)
    {
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
class DataAttributesThrowingAttribute
{
    /**
     * Create a new throwing test attribute.
     */
    public function __construct()
    {
        throw new RuntimeException('Attribute construction must remain lazy.');
    }
}

class DataAttributesObjectValue
{
    /**
     * Create a new object attribute value.
     */
    public function __construct(public readonly string $value)
    {
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
class DataAttributesObjectAttribute
{
    /**
     * Create a new object-bearing test attribute.
     */
    public function __construct(public readonly DataAttributesObjectValue $value)
    {
    }
}

#[DataAttributesThrowingAttribute]
class DataAttributesClassWithThrowingAttribute
{
}

#[DataAttributesConcreteAttribute('parent')]
class DataAttributesParentFixture
{
}

#[DataAttributesConcreteAttribute('child')]
class DataAttributesChildFixture extends DataAttributesParentFixture
{
}

#[MissingAttribute]
class DataAttributesUnknownAttributeFixture
{
}

class DataAttributesParameterFixture
{
    /**
     * Create a new parameter fixture.
     */
    public function __construct(
        #[DataAttributesObjectAttribute(new DataAttributesObjectValue('value'))]
        public readonly string $value,
    ) {
    }
}
