<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Attribute;
use Error;
use Hypervel\Support\ClassMetadataCache;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;

class ClassMetadataCacheTest extends TestCase
{
    public function testReflectClassReturnsCachedReflection(): void
    {
        $first = ClassMetadataCache::reflectClass(ClassMetadataCacheFixture::class);
        $second = ClassMetadataCache::reflectClass(new ClassMetadataCacheFixture);

        $this->assertSame($first, $second);
    }

    public function testReflectMethodReturnsCachedReflection(): void
    {
        $first = ClassMetadataCache::reflectMethod(ClassMetadataCacheFixture::class, 'greet');
        $second = ClassMetadataCache::reflectMethod(new ClassMetadataCacheFixture, 'greet');

        $this->assertSame($first, $second);
    }

    public function testReflectClassThrowsForMissingClass(): void
    {
        $this->expectException(ReflectionException::class);

        ClassMetadataCache::reflectClass('MissingClassMetadataCacheFixture');
    }

    public function testReflectMethodThrowsForMissingMethod(): void
    {
        $this->expectException(ReflectionException::class);

        ClassMetadataCache::reflectMethod(ClassMetadataCacheFixture::class, 'missingMethod');
    }

    public function testDefaultPropertiesAreCached(): void
    {
        $this->assertSame(['name' => 'hypervel'], ClassMetadataCache::defaultProperties(ClassMetadataCacheFixture::class));

        $defaultProperties = $this->staticProperty('defaultProperties');

        $this->assertArrayHasKey(ClassMetadataCacheFixture::class, $defaultProperties);
        $this->assertSame(['name' => 'hypervel'], $defaultProperties[ClassMetadataCacheFixture::class]);
    }

    public function testPropertiesAreCached(): void
    {
        $first = ClassMetadataCache::properties(ClassMetadataCacheFixture::class);
        $second = ClassMetadataCache::properties(ClassMetadataCacheFixture::class);

        $this->assertSame($first[0], $second[0]);
    }

    public function testClassAttributeMetadataIsCached(): void
    {
        $first = ClassMetadataCache::getAttribute(ClassMetadataCacheAttributedFixture::class, ClassMetadataCacheAttribute::class);
        $second = ClassMetadataCache::getAttribute(ClassMetadataCacheAttributedFixture::class, ClassMetadataCacheAttribute::class);

        $this->assertSame($first, $second);
        $this->assertSame($first?->instance, $second?->instance);
        $this->assertSame('class', $first?->instance->value);
        $this->assertSame(ClassMetadataCacheAttributedFixture::class, $first?->declaringClass->getName());
    }

    public function testMissingClassAttributeIsCachedAsNull(): void
    {
        $this->assertNull(ClassMetadataCache::getAttribute(ClassMetadataCacheFixture::class, ClassMetadataCacheAttribute::class));

        $attributes = $this->staticProperty('attributes');

        $this->assertArrayHasKey(ClassMetadataCacheFixture::class, $attributes);
        $this->assertArrayHasKey(ClassMetadataCacheAttribute::class, $attributes[ClassMetadataCacheFixture::class]);
        $this->assertNull($attributes[ClassMetadataCacheFixture::class][ClassMetadataCacheAttribute::class]);
    }

    public function testAttributeConstructorExceptionIsCachedAsNull(): void
    {
        $this->assertNull(ClassMetadataCache::getAttribute(ClassMetadataCacheExceptionFixture::class, ClassMetadataCacheExceptionAttribute::class));

        $attributes = $this->staticProperty('attributes');

        $this->assertArrayHasKey(ClassMetadataCacheExceptionAttribute::class, $attributes[ClassMetadataCacheExceptionFixture::class]);
        $this->assertNull($attributes[ClassMetadataCacheExceptionFixture::class][ClassMetadataCacheExceptionAttribute::class]);
    }

    public function testAttributeConstructorErrorBubblesAndIsNotCached(): void
    {
        try {
            ClassMetadataCache::getAttribute(ClassMetadataCacheErrorFixture::class, ClassMetadataCacheErrorAttribute::class);

            $this->fail('The attribute constructor error was not thrown.');
        } catch (Error $e) {
            $this->assertSame('Uncached attribute error.', $e->getMessage());
        }

        $attributes = $this->staticProperty('attributes');

        $this->assertArrayNotHasKey(ClassMetadataCacheErrorAttribute::class, $attributes[ClassMetadataCacheErrorFixture::class]);
    }

    public function testClassAttributeWalkFindsParentAndTraitAttributes(): void
    {
        $parentAttribute = ClassMetadataCache::getAttribute(ClassMetadataCacheChildFixture::class, ClassMetadataCacheAttribute::class);
        $traitAttribute = ClassMetadataCache::getAttribute(ClassMetadataCacheTraitFixture::class, ClassMetadataCacheAttribute::class);

        $this->assertSame('parent', $parentAttribute?->instance->value);
        $this->assertSame(ClassMetadataCacheParentFixture::class, $parentAttribute?->declaringClass->getName());
        $this->assertSame('trait', $traitAttribute?->instance->value);
        $this->assertSame(ClassMetadataCacheTraitFixture::class, $traitAttribute?->declaringClass->getName());
    }

    public function testConcreteClassAttributePresenceDoesNotWalkParentsOrTraits(): void
    {
        $this->assertFalse(ClassMetadataCache::hasClassAttribute(ClassMetadataCacheChildFixture::class, ClassMetadataCacheAttribute::class));
        $this->assertFalse(ClassMetadataCache::hasClassAttribute(ClassMetadataCacheTraitFixture::class, ClassMetadataCacheAttribute::class));
        $this->assertTrue(ClassMetadataCache::hasClassAttribute(ClassMetadataCacheParentFixture::class, ClassMetadataCacheAttribute::class));

        $classAttributePresence = $this->staticProperty('classAttributePresence');

        $this->assertArrayHasKey(ClassMetadataCacheAttribute::class, $classAttributePresence[ClassMetadataCacheChildFixture::class]);
        $this->assertFalse($classAttributePresence[ClassMetadataCacheChildFixture::class][ClassMetadataCacheAttribute::class]);
    }

    public function testPropertyAttributePresenceIsCached(): void
    {
        $properties = ClassMetadataCache::properties(ClassMetadataCachePropertyFixture::class);
        $marked = $this->propertyByName($properties, 'marked');
        $unmarked = $this->propertyByName($properties, 'unmarked');

        $this->assertTrue(ClassMetadataCache::hasPropertyAttribute($marked, ClassMetadataCachePropertyAttribute::class));
        $this->assertFalse(ClassMetadataCache::hasPropertyAttribute($unmarked, ClassMetadataCachePropertyAttribute::class));

        $propertyAttributePresence = $this->staticProperty('propertyAttributePresence');

        $this->assertTrue($propertyAttributePresence[ClassMetadataCachePropertyFixture::class]['marked'][ClassMetadataCachePropertyAttribute::class]);
        $this->assertArrayHasKey(ClassMetadataCachePropertyAttribute::class, $propertyAttributePresence[ClassMetadataCachePropertyFixture::class]['unmarked']);
        $this->assertFalse($propertyAttributePresence[ClassMetadataCachePropertyFixture::class]['unmarked'][ClassMetadataCachePropertyAttribute::class]);
    }

    public function testPropertyAttributePresenceUsesDeclaringClassKey(): void
    {
        $properties = ClassMetadataCache::properties(ClassMetadataCachePropertyChildFixture::class);
        $marked = $this->propertyByName($properties, 'marked');

        $this->assertTrue(ClassMetadataCache::hasPropertyAttribute($marked, ClassMetadataCachePropertyAttribute::class));

        $propertyAttributePresence = $this->staticProperty('propertyAttributePresence');

        $this->assertArrayHasKey(ClassMetadataCachePropertyFixture::class, $propertyAttributePresence);
        $this->assertArrayNotHasKey(ClassMetadataCachePropertyChildFixture::class, $propertyAttributePresence);
    }

    public function testFlushStateClearsCachedMetadata(): void
    {
        $classBefore = ClassMetadataCache::reflectClass(ClassMetadataCacheFixture::class);
        $methodBefore = ClassMetadataCache::reflectMethod(ClassMetadataCacheFixture::class, 'greet');

        ClassMetadataCache::getAttribute(ClassMetadataCacheAttributedFixture::class, ClassMetadataCacheAttribute::class);
        ClassMetadataCache::hasClassAttribute(ClassMetadataCacheParentFixture::class, ClassMetadataCacheAttribute::class);
        ClassMetadataCache::flushState();

        $this->assertSame([], $this->staticProperty('methods'));
        $this->assertSame([], $this->staticProperty('attributes'));
        $this->assertSame([], $this->staticProperty('classAttributePresence'));

        $classAfter = ClassMetadataCache::reflectClass(ClassMetadataCacheFixture::class);
        $methodAfter = ClassMetadataCache::reflectMethod(ClassMetadataCacheFixture::class, 'greet');

        $this->assertNotSame($classBefore, $classAfter);
        $this->assertNotSame($methodBefore, $methodAfter);
    }

    /**
     * Get a static property from the metadata cache.
     */
    protected function staticProperty(string $property): array
    {
        $reflection = new ReflectionClass(ClassMetadataCache::class);

        return $reflection->getProperty($property)->getValue();
    }

    /**
     * Get the property with the given name from a reflection property list.
     *
     * @param list<ReflectionProperty> $properties
     */
    protected function propertyByName(array $properties, string $name): ReflectionProperty
    {
        foreach ($properties as $property) {
            if ($property->getName() === $name) {
                return $property;
            }
        }

        $this->fail("Property [{$name}] was not found.");
    }
}

class ClassMetadataCacheFixture
{
    public string $name = 'hypervel';

    public function greet(): string
    {
        return 'hello';
    }
}

#[ClassMetadataCacheAttribute('class')]
class ClassMetadataCacheAttributedFixture
{
}

#[ClassMetadataCacheAttribute('parent')]
class ClassMetadataCacheParentFixture
{
}

class ClassMetadataCacheChildFixture extends ClassMetadataCacheParentFixture
{
}

#[ClassMetadataCacheAttribute('trait')]
trait ClassMetadataCacheTrait
{
}

class ClassMetadataCacheTraitFixture
{
    use ClassMetadataCacheTrait;
}

class ClassMetadataCachePropertyFixture
{
    #[ClassMetadataCachePropertyAttribute]
    public string $marked = 'yes';

    public string $unmarked = 'no';
}

class ClassMetadataCachePropertyChildFixture extends ClassMetadataCachePropertyFixture
{
}

#[ClassMetadataCacheExceptionAttribute]
class ClassMetadataCacheExceptionFixture
{
}

#[ClassMetadataCacheErrorAttribute]
class ClassMetadataCacheErrorFixture
{
}

#[Attribute(Attribute::TARGET_CLASS)]
readonly class ClassMetadataCacheAttribute
{
    public function __construct(
        public string $value,
    ) {
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class ClassMetadataCachePropertyAttribute
{
}

#[Attribute(Attribute::TARGET_CLASS)]
readonly class ClassMetadataCacheExceptionAttribute
{
    public function __construct()
    {
        throw new RuntimeException('Cached as null.');
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
readonly class ClassMetadataCacheErrorAttribute
{
    public function __construct()
    {
        throw new Error('Uncached attribute error.');
    }
}
