<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\AutoClosureLazy;
use Hypervel\Data\Attributes\AutoInertiaLazy;
use Hypervel\Data\Attributes\AutoLazy;
use Hypervel\Data\Attributes\AutoWhenLoadedLazy;
use Hypervel\Data\Attributes\Computed;
use Hypervel\Data\Attributes\Hidden;
use Hypervel\Data\Attributes\LoadRelation;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\MapName;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Attributes\WithCastAndTransformer;
use Hypervel\Data\Attributes\WithoutValidation;
use Hypervel\Data\Attributes\WithTransformer;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Casts\DateTimeInterfaceCast;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Lazy;
use Hypervel\Data\Mappers\KebabCaseMapper;
use Hypervel\Data\Mappers\SnakeCaseMapper;
use Hypervel\Data\Optional;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Factories\DataAttributesCollectionFactory;
use Hypervel\Data\Support\Factories\DataParameterFactory;
use Hypervel\Data\Support\Factories\DataPropertyFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Data\Transformers\DateTimeInterfaceTransformer;
use Hypervel\Data\Transformers\Transformer;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\Models\DummyModel;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDataWithPropertyHooks;
use ReflectionAttribute;
use ReflectionClass;

class DataPropertyTest extends TestCase
{
    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanGetTheCastAttributeWithArguments(): void
    {
        $helper = $this->resolveHelper(new class {
            #[WithCast(DateTimeInterfaceCast::class, 'd-m-y')]
            public SimpleData $property;
        });

        $this->assertEquals(new DateTimeInterfaceCast('d-m-y'), $helper->cast?->newInstance()->get());
    }

    public function testCanGetTheTransformerAttribute(): void
    {
        $helper = $this->resolveHelper(new class {
            #[WithTransformer(DateTimeInterfaceTransformer::class)]
            public SimpleData $property;
        });

        $this->assertEquals(new DateTimeInterfaceTransformer, $helper->transformer?->newInstance()->get());
    }

    public function testCanGetTheTransformerAttributeWithArguments(): void
    {
        $helper = $this->resolveHelper(new class {
            #[WithTransformer(DateTimeInterfaceTransformer::class, 'd-m-y')]
            public SimpleData $property;
        });

        $this->assertEquals(new DateTimeInterfaceTransformer('d-m-y'), $helper->transformer?->newInstance()->get());
    }

    public function testCanGetTheCastWithTransformerAttribute(): void
    {
        $helper = $this->resolveHelper(new class {
            #[WithCastAndTransformer(FakeCastTransformer::class)]
            public SimpleData $property;
        });

        $this->assertEquals(new FakeCastTransformer, $helper->transformer?->newInstance()->get());
        $this->assertEquals(new FakeCastTransformer, $helper->cast?->newInstance()->get());
    }

    public function testCanGetTheMappedInputName(): void
    {
        $helper = $this->resolveHelper(new class {
            #[MapInputName('other')]
            public SimpleData $property;
        });

        $this->assertEquals('other', $helper->inputMappedName);
    }

    public function testCanGetTheMappedOutputName(): void
    {
        $helper = $this->resolveHelper(new class {
            #[MapOutputName('other')]
            public SimpleData $property;
        });

        $this->assertEquals('other', $helper->outputMappedName);
    }

    public function testCanGetTheDefaultValue(): void
    {
        $helper = $this->resolveHelper(new class {
            public string $property;
        });

        $this->assertFalse($helper->hasDefaultValue);

        $helper = $this->resolveHelper(new class {
            public string $property = 'hello';
        });

        // Metadata records only whether a default exists; the value itself is read when needed.
        $this->assertTrue($helper->hasDefaultValue);
    }

    public function testWillIgnoreAnOptionalValueAsADefaultValue(): void
    {
        $helper = $this->resolveHelper(new class {
            /**
             * Create the object with an Optional default.
             */
            public function __construct(
                public string|Optional $property = new Optional,
            ) {
            }
        });

        $this->assertFalse($helper->hasDefaultValue);
    }

    public function testCanCheckIfThePropertyIsPromoted(): void
    {
        $helper = $this->resolveHelper(new class('') {
            /**
             * Create the object with a promoted property.
             */
            public function __construct(
                public string $property,
            ) {
            }
        });

        $this->assertTrue($helper->isPromoted);

        $helper = $this->resolveHelper(new class {
            public string $property;
        });

        $this->assertFalse($helper->isPromoted);
    }

    public function testCanCheckIfAPropertyShouldBeValidated(): void
    {
        $this->assertTrue($this->resolveHelper(new class {
            public string $property;
        })->validate);

        $this->assertFalse($this->resolveHelper(new class {
            #[WithoutValidation]
            public string $property;
        })->validate);

        $this->assertFalse($this->resolveHelper(new class {
            #[Computed]
            public string $property;
        })->validate);
    }

    public function testCanCheckIfAPropertyIsComputed(): void
    {
        $this->assertFalse($this->resolveHelper(new class {
            public string $property;
        })->computed);

        $this->assertTrue($this->resolveHelper(new class {
            #[Computed]
            public string $property;
        })->computed);
    }

    public function testCanCheckIfAVirtualPropertyIsComputed(): void
    {
        $this->assertTrue($this->resolveHelper(new SimpleDataWithPropertyHooks, propertyName: 'virtual')->computed);
    }

    public function testDoesNotMarkABackedPropertyAsComputed(): void
    {
        $this->assertFalse($this->resolveHelper(new SimpleDataWithPropertyHooks, propertyName: 'backed')->computed);
    }

    public function testCanCheckIfAPropertyIsHidden(): void
    {
        $this->assertFalse($this->resolveHelper(new class {
            public string $property;
        })->hidden);

        $this->assertTrue($this->resolveHelper(new class {
            #[Hidden]
            public string $property;
        })->hidden);
    }

    public function testCanCheckIfAPropertyIsAutoLazy(): void
    {
        $this->assertNull($this->resolveHelper(new class {
            public string $property;
        })->autoLazy);

        $this->assertInstanceOf(AutoLazy::class, $this->resolveHelper(new class {
            #[AutoLazy]
            public string $property;
        })->autoLazy?->newInstance());

        $this->assertInstanceOf(AutoInertiaLazy::class, $this->resolveHelper(new class {
            #[AutoInertiaLazy]
            public string|Lazy $property;
        })->autoLazy?->newInstance());

        $this->assertInstanceOf(AutoWhenLoadedLazy::class, $this->resolveHelper(new class {
            #[AutoWhenLoadedLazy('relation')]
            public string|Lazy $property;
        })->autoLazy?->newInstance());

        $this->assertInstanceOf(AutoClosureLazy::class, $this->resolveHelper(new class {
            #[AutoClosureLazy]
            public string $property;
        })->autoLazy?->newInstance());
    }

    public function testWillSetAPropertyAsAutoLazyWhenTheClassIsAutoLazyAndALazyTypeIsAllowed(): void
    {
        $classAutoLazy = (new ReflectionClass(AutoLazyPropertyClass::class))->getAttributes(AutoLazy::class)[0];

        $this->assertNull($this->resolveHelper(new class {
            public string $property;
        }, $classAutoLazy)->autoLazy);

        $this->assertInstanceOf(AutoLazy::class, $this->resolveHelper(new class {
            public string|Lazy $property;
        }, $classAutoLazy)->autoLazy?->newInstance());
    }

    public function testWontThrowAnErrorIfNonExistingAttributeIsUsedOnADataClassProperty(): void
    {
        $this->assertEquals('hello', NonExistingPropertyAttributeData::from(['property' => 'hello'])->property);
        $this->assertEquals('hello', PhpStormAttributeData::from(['property' => 'hello'])->property);
        $this->assertEquals('hello', PhpStormAttributeData::from('{"property": "hello"}')->property);
        $this->assertEquals('hello', PhpStormAttributeData::from((object) ['property' => 'hello'])->property);
        $this->assertEquals(1, ModelWithPhpStormAttributePropertyData::from((new DummyModel)->fill(['id' => 1]))->id);
        $this->assertEquals(1, ModelWithPromotedPhpStormAttributePropertyData::from((new DummyModel)->fill(['id' => 1]))->id);
    }

    /**
     * Test property flags and extension recipes.
     */
    public function testPropertyMetadataCompilesFlagsMappingsAndRecipes(): void
    {
        [$factory, $config, $mapperResolver] = $this->factory([
            'casts' => ['string' => PropertyFallbackCast::class],
            'transformers' => ['string' => PropertyFallbackTransformer::class],
        ]);
        $class = new ReflectionClass(DataPropertyFixture::class);
        $property = $this->buildProperty($factory, $class, 'displayName', $config, $mapperResolver);

        $this->assertSame('displayName', $property->name);
        $this->assertSame(DataPropertyFixture::class, $property->className);
        $this->assertTrue($property->isPromoted);
        $this->assertTrue($property->isConstructorParameter);
        $this->assertTrue($property->isReadonly);
        $this->assertTrue($property->hasDefaultValue);
        $this->assertFalse($property->validate);
        $this->assertTrue($property->hidden);
        $this->assertSame('wire.name', $property->inputMappedName);
        $this->assertSame(['wire', 'name'], $property->inputMappedPath);
        $this->assertSame(['wire', 'name'], $property->inputPath('wire.name'));
        $this->assertSame(['displayName'], $property->inputPath('displayName'));
        $this->assertSame('display', $property->outputMappedName);
        $this->assertSame([PropertyFallbackCast::class], $property->configuredCasts);
        $this->assertSame([PropertyFallbackTransformer::class], $property->configuredTransformers);
        $this->assertInstanceOf(ReflectionAttribute::class, $property->autoLazy);
        $this->assertInstanceOf(ReflectionAttribute::class, $property->cast);
        $this->assertInstanceOf(ReflectionAttribute::class, $property->transformer);
        $this->assertSame(WithCast::class, $property->cast?->getName());
        $this->assertSame(WithTransformer::class, $property->transformer?->getName());
        $this->assertSame('displayName', $property->reflection->name);
        $this->assertSame(DataPropertyFixture::class, $property->reflection->getDeclaringClass()->name);

        $relation = $this->buildProperty($factory, $class, 'relation', $config, $mapperResolver);
        $morph = $this->buildProperty($factory, $class, 'type', $config, $mapperResolver);

        $this->assertTrue($relation->loadRelation);
        $this->assertTrue($morph->morphable);
    }

    /**
     * Test defaults and output-only properties without retaining default objects.
     */
    public function testDefaultsComputedAndVirtualPropertiesAreCompiled(): void
    {
        [$factory, $config, $mapperResolver] = $this->factory();
        $class = new ReflectionClass(DataPropertyFixture::class);
        $nonPromoted = $this->buildProperty(
            $factory,
            $class,
            'nonPromoted',
            $config,
            $mapperResolver,
        );
        $computed = $this->buildProperty($factory, $class, 'computed', $config, $mapperResolver);
        $virtual = $this->buildProperty($factory, $class, 'virtual', $config, $mapperResolver);
        $backedHook = $this->buildProperty($factory, $class, 'backedHook', $config, $mapperResolver);

        $this->assertFalse($nonPromoted->isPromoted);
        $this->assertFalse($nonPromoted->isConstructorParameter);
        $this->assertTrue($nonPromoted->hasDefaultValue);
        $this->assertTrue($computed->computed);
        $this->assertFalse($computed->validate);
        $this->assertTrue($virtual->hasGetHook);
        $this->assertTrue($virtual->computed);
        $this->assertFalse($virtual->validate);
        $this->assertTrue($backedHook->hasGetHook);
        $this->assertFalse($backedHook->computed);
    }

    /**
     * Test constructor-bound property ownership and default precedence.
     */
    public function testConstructorParametersOwnBoundPropertyDefaults(): void
    {
        [$factory, $config, $mapperResolver] = $this->factory();
        $class = new ReflectionClass(DataPropertyFixture::class);
        $readonly = $this->buildProperty($factory, $class, 'readonlyBound', $config, $mapperResolver);
        $defaulted = $this->buildProperty($factory, $class, 'constructorDefault', $config, $mapperResolver);
        $required = $this->buildProperty(
            $factory,
            $class,
            'requiredWithPropertyDefault',
            $config,
            $mapperResolver,
        );

        $this->assertTrue($readonly->isConstructorParameter);
        $this->assertTrue($readonly->isReadonly);
        $this->assertFalse($readonly->hasDefaultValue);
        $this->assertTrue($defaulted->isConstructorParameter);
        $this->assertTrue($defaulted->hasDefaultValue);
        $this->assertTrue($required->isConstructorParameter);
        $this->assertFalse($required->hasDefaultValue);
    }

    /**
     * Test class and configured mapper precedence.
     */
    public function testNameMappersAreResolvedOnceWithPropertyPrecedence(): void
    {
        [$factory, $config, $mapperResolver] = $this->factory([
            'name_mapping_strategy' => [
                'input' => KebabCaseMapper::class,
                'output' => KebabCaseMapper::class,
            ],
        ]);
        $class = new ReflectionClass(DataPropertyFixture::class);
        $mapped = $this->buildProperty($factory, $class, 'createdAt', $config, $mapperResolver);
        $numeric = $this->buildProperty($factory, $class, 'numeric', $config, $mapperResolver);

        $this->assertSame('created_at', $mapped->inputMappedName);
        $this->assertSame(['created_at'], $mapped->inputMappedPath);
        $this->assertSame('created_at', $mapped->outputMappedName);
        $this->assertSame(0, $numeric->inputMappedName);
        $this->assertSame([0], $numeric->inputMappedPath);
        $this->assertSame('numeric', $numeric->outputMappedName);
    }

    /**
     * Test model relation resolution follows the normalized property name.
     */
    public function testResolvesOnlyMarkedEloquentRelations(): void
    {
        [$factory, $config, $mapperResolver] = $this->factory();
        $class = new ReflectionClass(DataPropertyFixture::class);
        $model = new PropertyRelationModel;
        $relation = $this->buildProperty($factory, $class, 'relation', $config, $mapperResolver);
        $camelRelation = $this->buildProperty(
            $factory,
            $class,
            'loadedProfile',
            $config,
            $mapperResolver,
        );
        $unmarked = $this->buildProperty($factory, $class, 'createdAt', $config, $mapperResolver);

        $this->assertSame('relation', $relation->resolveModelRelation($model));
        $this->assertSame('loadedProfile', $camelRelation->resolveModelRelation($model));
        $this->assertNull($unmarked->resolveModelRelation($model));

        $model->relations = [];

        $this->assertNull($relation->resolveModelRelation($model));
    }

    /**
     * Build one property of an object's class with the default configuration.
     *
     * @param null|ReflectionAttribute<object> $classAutoLazy
     */
    protected function resolveHelper(
        object $class,
        ?ReflectionAttribute $classAutoLazy = null,
        string $propertyName = 'property',
    ): DataProperty {
        [$factory] = $this->factory();
        $reflectionClass = new ReflectionClass($class);
        $constructorParameter = null;

        foreach ($reflectionClass->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->name === $propertyName) {
                $constructorParameter = (new DataParameterFactory(
                    new DataTypeFactory(new PhpDocTypeNameResolver, new DataIterableAnnotationReader),
                ))->build($parameter, $reflectionClass);
            }
        }

        return $factory->build(
            reflectionProperty: $reflectionClass->getProperty($propertyName),
            reflectionClass: $reflectionClass,
            constructorParameter: $constructorParameter,
            classAutoLazy: $classAutoLazy,
        );
    }

    /**
     * Build one fixture property with its constructor default metadata.
     *
     * @param ReflectionClass<object> $class
     */
    protected function buildProperty(
        DataPropertyFactory $factory,
        ReflectionClass $class,
        string $name,
        DataConfig $config,
        NameMapperResolver $mapperResolver,
    ): DataProperty {
        $reflectionProperty = $class->getProperty($name);
        $constructorParameter = null;

        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->name === $name) {
                $constructorParameter = (new DataParameterFactory(
                    new DataTypeFactory(new PhpDocTypeNameResolver, new DataIterableAnnotationReader),
                ))->build($parameter, $class);

                break;
            }
        }

        $classAttributes = DataAttributesCollectionFactory::buildFromReflectionClass($class);
        $defaultInputMapper = $mapperResolver->resolveConfigured($config->inputNameMapper);
        $defaultOutputMapper = $mapperResolver->resolveConfigured($config->outputNameMapper);

        return $factory->build(
            reflectionProperty: $reflectionProperty,
            reflectionClass: $class,
            constructorParameter: $constructorParameter,
            classInputNameMapper: $mapperResolver->resolveInput($classAttributes, $defaultInputMapper),
            classOutputNameMapper: $mapperResolver->resolveOutput($classAttributes, $defaultOutputMapper),
        );
    }

    /**
     * Create a property factory and its boot-stable collaborators.
     *
     * @return array{DataPropertyFactory, DataConfig, NameMapperResolver}
     */
    protected function factory(array $overrides = []): array
    {
        $defaults = require __DIR__ . '/../../../src/data/config/data.php';
        $config = new DataConfig(new Repository([
            'data' => array_replace($defaults, $overrides),
        ]));
        $mapperResolver = new NameMapperResolver(new Container);
        $typeFactory = new DataTypeFactory(new PhpDocTypeNameResolver, new DataIterableAnnotationReader);

        return [
            new DataPropertyFactory($typeFactory, $config, $mapperResolver),
            $config,
            $mapperResolver,
        ];
    }
}

#[MapName(SnakeCaseMapper::class)]
class DataPropertyFixture
{
    public readonly string $readonlyBound;

    public string $requiredWithPropertyDefault = 'property-default';

    public string $constructorDefault;

    /**
     * Create a new property fixture.
     */
    public function __construct(
        string $readonlyBound,
        string $requiredWithPropertyDefault,
        #[AutoLazy]
        #[Hidden]
        #[MapInputName('wire.name')]
        #[MapOutputName('display')]
        #[WithCast(PropertyCast::class)]
        #[WithTransformer(PropertyTransformer::class)]
        #[WithoutValidation]
        public readonly string $displayName = 'Taylor',
        string $constructorDefault = 'constructor-default',
    ) {
        $this->readonlyBound = $readonlyBound;
        $this->requiredWithPropertyDefault = $requiredWithPropertyDefault;
        $this->constructorDefault = $constructorDefault;
    }

    #[LoadRelation]
    public PropertyRelation $relation;

    #[LoadRelation]
    public PropertyRelation $loadedProfile;

    #[PropertyForMorph]
    public string $type;

    public string $nonPromoted = 'default';

    #[Computed]
    public string $computed = 'computed';

    public string $virtual {
        get => 'virtual';
    }

    public string $backedHook = 'backed' {
        get => strtoupper($this->backedHook);
    }

    public string $createdAt;

    #[MapInputName(0)]
    public string $numeric;
}

class PropertyRelation
{
}

class PropertyRelationModel extends Model
{
    /** @var list<string> */
    public array $relations = ['relation', 'loadedProfile'];

    /**
     * Determine if a fixture relation exists.
     */
    public function isRelation(string $key): bool
    {
        return in_array($key, $this->relations, true);
    }
}

class PropertyCast implements Cast
{
    /**
     * Cast a property value.
     */
    public function cast(
        DataProperty $property,
        mixed $value,
        array $properties,
        CreationContext $context,
    ): mixed {
        return $value;
    }
}

class PropertyFallbackCast extends PropertyCast
{
}

class PropertyTransformer implements Transformer
{
    /**
     * Transform a property value.
     */
    public function transform(
        DataProperty $property,
        mixed $value,
        TransformationContext $context,
    ): mixed {
        return $value;
    }
}

class PropertyFallbackTransformer extends PropertyTransformer
{
}

class FakeCastTransformer implements Cast, Transformer
{
    /**
     * Cast a property value.
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): mixed
    {
        return $value;
    }

    /**
     * Transform a property value.
     */
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): mixed
    {
        return $value;
    }
}

#[AutoLazy]
class AutoLazyPropertyClass
{
}

class NonExistingPropertyAttributeData extends Data
{
    #[\Foo\Bar]
    public readonly string $property;

    /**
     * Create a fixture whose property uses an undefined attribute.
     */
    public function __construct(string $property)
    {
        $this->property = $property;
    }
}

class PhpStormAttributeData extends Data
{
    #[\JetBrains\PhpStorm\Immutable]
    public readonly string $property;

    /**
     * Create a fixture whose property uses an IDE attribute.
     */
    public function __construct(string $property)
    {
        $this->property = $property;
    }
}

class ModelWithPhpStormAttributePropertyData extends Data
{
    #[\JetBrains\PhpStorm\Immutable]
    public int $id;

    /**
     * Create a model fixture whose property uses an IDE attribute.
     */
    public function __construct(int $id)
    {
        $this->id = $id;
    }

    /**
     * Create the fixture from a dummy model.
     */
    public static function fromDummyModel(DummyModel $model): self
    {
        return new self($model->id);
    }
}

class ModelWithPromotedPhpStormAttributePropertyData extends Data
{
    /**
     * Create a model fixture whose promoted property uses an IDE attribute.
     */
    public function __construct(
        #[\JetBrains\PhpStorm\Immutable]
        public int $id
    ) {
    }

    /**
     * Create the fixture from a dummy model.
     */
    public static function fromDummyModel(DummyModel $model): self
    {
        return new self($model->id);
    }
}
