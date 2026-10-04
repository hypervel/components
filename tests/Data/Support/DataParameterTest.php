<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

use Hypervel\Container\Attributes\Config;
use Hypervel\Data\Data;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataParameter;
use Hypervel\Data\Support\Factories\DataParameterFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use ReflectionParameter;

class DataParameterTest extends TestCase
{
    public function testCanCreateADataParameter(): void
    {
        $class = new class('', '', '', new CreationContext(SimpleData::class)) extends Data {
            /**
             * Create a data object with plain, untyped, promoted, creation context and defaulted parameters.
             * @param mixed $withoutType
             */
            public function __construct(
                string $nonPromoted,
                public $withoutType,
                public string $property,
                CreationContext $creationContext,
                public string $propertyWithDefault = 'hello',
            ) {
            }
        };
        $factory = new DataParameterFactory(new DataTypeFactory(new PhpDocTypeNameResolver, new DataIterableAnnotationReader));
        $parameter = fn (string $name): DataParameter => $factory->build(
            new ReflectionParameter([$class::class, '__construct'], $name),
            new ReflectionClass($class),
        );

        // A creation context is recognized by its class name rather than Spatie's isCreationContext().
        $nonPromoted = $parameter('nonPromoted');

        $this->assertSame('nonPromoted', $nonPromoted->name);
        $this->assertFalse($nonPromoted->isPromoted);
        $this->assertFalse($nonPromoted->hasDefaultValue);
        $this->assertSame('string', $nonPromoted->type->getNamedTypes()[0]->name);
        $this->assertFalse($nonPromoted->type->isNullable);
        $this->assertNull($nonPromoted->className);

        $withoutType = $parameter('withoutType');

        $this->assertSame('withoutType', $withoutType->name);
        $this->assertTrue($withoutType->isPromoted);
        $this->assertFalse($withoutType->hasDefaultValue);
        $this->assertTrue($withoutType->type->isMixed);
        $this->assertTrue($withoutType->type->isNullable);
        $this->assertNull($withoutType->className);

        $property = $parameter('property');

        $this->assertSame('property', $property->name);
        $this->assertTrue($property->isPromoted);
        $this->assertFalse($property->hasDefaultValue);
        $this->assertSame('string', $property->type->getNamedTypes()[0]->name);
        $this->assertFalse($property->type->isNullable);
        $this->assertNull($property->className);

        $creationContext = $parameter('creationContext');

        $this->assertSame('creationContext', $creationContext->name);
        $this->assertFalse($creationContext->isPromoted);
        $this->assertFalse($creationContext->hasDefaultValue);
        $this->assertSame(CreationContext::class, $creationContext->type->getNamedTypes()[0]->name);
        $this->assertFalse($creationContext->type->isNullable);
        $this->assertSame(CreationContext::class, $creationContext->className);

        $propertyWithDefault = $parameter('propertyWithDefault');

        // Defaults are read from reflection when needed, so the metadata keeps none (README).
        $this->assertSame('propertyWithDefault', $propertyWithDefault->name);
        $this->assertTrue($propertyWithDefault->isPromoted);
        $this->assertTrue($propertyWithDefault->hasDefaultValue);
        $this->assertSame('hello', $propertyWithDefault->reflection->getDefaultValue());
        $this->assertSame('string', $propertyWithDefault->type->getNamedTypes()[0]->name);
        $this->assertFalse($propertyWithDefault->type->isNullable);
        $this->assertNull($propertyWithDefault->className);
    }

    /**
     * Test immutable parameter metadata without retaining default values.
     */
    public function testParameterMetadataPreservesConstructionRecipes(): void
    {
        $class = new ReflectionClass(DataParameterFixture::class);
        $factory = new DataParameterFactory(new DataTypeFactory(new PhpDocTypeNameResolver, new DataIterableAnnotationReader));

        $plainReflection = new ReflectionParameter([DataParameterFixture::class, '__construct'], 'plain');
        $plain = $factory->build($plainReflection, $class);

        $this->assertSame('plain', $plain->name);
        $this->assertSame(0, $plain->position);
        $this->assertFalse($plain->isPromoted);
        $this->assertFalse($plain->isVariadic);
        $this->assertFalse($plain->hasDefaultValue);
        $this->assertFalse($plain->hasAttributes);
        $this->assertNull($plain->className);
        $this->assertSame('string', $plain->type->getNamedTypes()[0]->name);
        $this->assertSame($plainReflection, $plain->reflection);
        $this->assertNull($plain->contextualAttribute);

        $contextual = $factory->build(
            new ReflectionParameter([DataParameterFixture::class, '__construct'], 'contextual'),
            $class,
        );

        $this->assertTrue($contextual->isPromoted);
        $this->assertTrue($contextual->hasAttributes);
        $this->assertNull($contextual->className);
        $this->assertSame(Config::class, $contextual->contextualAttribute?->getName());

        $defaulted = $factory->build(
            new ReflectionParameter([DataParameterFixture::class, '__construct'], 'defaulted'),
            $class,
        );

        $this->assertTrue($defaulted->hasDefaultValue);
        $this->assertTrue($defaulted->type->isMixed);
        $this->assertTrue($defaulted->type->isNullable);

        $variadic = $factory->build(
            new ReflectionParameter([DataParameterFixture::class, '__construct'], 'values'),
            $class,
        );

        $this->assertSame(3, $variadic->position);
        $this->assertTrue($variadic->isVariadic);
        $this->assertFalse($variadic->hasDefaultValue);
        $this->assertNull($variadic->className);
        $this->assertSame('int', $variadic->type->getNamedTypes()[0]->name);

        $dependency = $factory->build(
            new ReflectionParameter([DataParameterFixture::class, 'fromDependencies'], 'dependency'),
            $class,
        );
        $dependencies = $factory->build(
            new ReflectionParameter([DataParameterFixture::class, 'fromDependencies'], 'dependencies'),
            $class,
        );

        $this->assertSame(DataParameterDependency::class, $dependency->className);
        $this->assertSame(DataParameterDependency::class, $dependencies->className);
        $this->assertFalse($dependency->isVariadic);
        $this->assertTrue($dependencies->isVariadic);
    }
}

class DataParameterFixture
{
    /**
     * Create a new fixture.
     */
    public function __construct(
        string $plain,
        #[Config('app.name')]
        public string $contextual,
        public mixed $defaulted = null,
        int ...$values,
    ) {
    }

    /**
     * Create a fixture from dependencies.
     */
    public static function fromDependencies(
        DataParameterDependency $dependency,
        DataParameterDependency ...$dependencies,
    ): self {
        return new self('value');
    }
}

class DataParameterDependency
{
}
