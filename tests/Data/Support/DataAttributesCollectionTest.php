<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

use Attribute;
use Hypervel\Data\Support\Factories\DataAttributesCollectionFactory;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

class DataAttributesCollectionTest extends TestCase
{
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
     * Test that recipes are grouped by concrete, parent, and interface types.
     */
    public function testAttributesAreGroupedByConcreteParentAndInterfaceTypes(): void
    {
        $attributes = DataAttributesCollectionFactory::buildFromReflectionProperty(
            new ReflectionProperty(DataAttributesPropertyFixture::class, 'value'),
        );

        $concrete = $attributes->first(DataAttributesConcreteAttribute::class);

        $this->assertNotNull($concrete);
        $this->assertSame($concrete, $attributes->first(DataAttributesBaseAttribute::class));
        $this->assertSame($concrete, $attributes->first(DataAttributesAttributeContract::class));
        $this->assertSame(['first', 'second'], array_map(
            fn ($attribute): string => $attribute->newInstance()->name,
            $attributes->all(DataAttributesConcreteAttribute::class),
        ));
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

        $this->assertFalse($attributes->has('Hypervel\\Tests\\Data\\Support\\MissingAttribute'));
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

interface DataAttributesAttributeContract
{
}

class DataAttributesBaseAttribute
{
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class DataAttributesConcreteAttribute extends DataAttributesBaseAttribute implements DataAttributesAttributeContract
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

class DataAttributesPropertyFixture
{
    #[DataAttributesConcreteAttribute('first')]
    #[DataAttributesConcreteAttribute('second')]
    public string $value;
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
