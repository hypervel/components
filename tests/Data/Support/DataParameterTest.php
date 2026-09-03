<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

use Hypervel\Container\Attributes\Config;
use Hypervel\Data\Support\Factories\DataParameterFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use ReflectionParameter;

class DataParameterTest extends TestCase
{
    /**
     * Test immutable parameter metadata without retaining default values.
     */
    public function testParameterMetadataPreservesConstructionRecipes(): void
    {
        $class = new ReflectionClass(DataParameterFixture::class);
        $factory = new DataParameterFactory(new DataTypeFactory(new PhpDocTypeNameResolver));

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
