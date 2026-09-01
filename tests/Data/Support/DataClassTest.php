<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

use Attribute;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\ContextualAttribute;
use Hypervel\Data\Attributes\Computed;
use Hypervel\Data\Attributes\Hidden;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\MapName;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Attributes\MergeValidationRules;
use Hypervel\Data\Exceptions\InvalidDataDeclaration;
use Hypervel\Data\Mappers\SnakeCaseMapper;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Factories\DataClassFactory;
use Hypervel\Data\Support\Factories\DataMethodFactory;
use Hypervel\Data\Support\Factories\DataParameterFactory;
use Hypervel\Data\Support\Factories\DataPropertyFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Foundation\Http\Attributes\ErrorBag;
use Hypervel\Foundation\Http\Attributes\FailOnUnknownFields;
use Hypervel\Foundation\Http\Attributes\RedirectTo;
use Hypervel\Foundation\Http\Attributes\RedirectToRoute;
use Hypervel\Foundation\Http\Attributes\StopOnFirstFailure;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\ChildScope\ChildAnnotations;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ChildClassItem;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ConstructorItem;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\InlineItem;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ParentClassItem;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;

class DataClassTest extends TestCase
{
    /**
     * Test class-level metadata, methods, mappings, and request attributes.
     */
    public function testClassMetadataCompilesIntoImmutableArrays(): void
    {
        $class = $this->factory()->build(new ReflectionClass(DataClassMetadataFixture::class));

        $this->assertSame(DataClassMetadataFixture::class, $class->name);
        $this->assertSame(['firstName', 'lastName'], array_keys($class->properties));
        $this->assertSame(['firstName', 'lastName'], array_column($class->constructorParameters, 'name'));
        $this->assertSame(['fromString', 'collectStrings'], array_keys($class->methods));
        $this->assertTrue($class->hasLifecycleMethod('rules'));
        $this->assertFalse($class->hasLifecycleMethod('authorize'));
        $this->assertTrue($class->mergeValidationRules);
        $this->assertTrue($class->failOnUnknownFields);
        $this->assertTrue($class->stopOnFirstFailure);
        $this->assertSame('metadata', $class->errorBag);
        $this->assertSame('/metadata', $class->redirect);
        $this->assertSame('metadata.store', $class->redirectRoute);
        $this->assertSame('first_name', $class->properties['firstName']->inputMappedName);
        $this->assertSame('last_name', $class->properties['lastName']->outputMappedName);
        $this->assertSame([
            'first_name' => 'firstName',
            'last_name' => 'lastName',
        ], $class->outputMappedProperties);
        $this->assertFalse($class->plainTransform);
    }

    /**
     * Test constructor-bound ownership and default precedence.
     */
    public function testConstructorBoundPropertiesUseConstructorMetadata(): void
    {
        $class = $this->factory()->build(new ReflectionClass(ConstructorBindingDataFixture::class));

        $this->assertTrue($class->properties['readonlyName']->isConstructorParameter);
        $this->assertTrue($class->properties['readonlyName']->isReadonly);
        $this->assertFalse($class->properties['readonlyName']->hasDefaultValue);
        $this->assertTrue($class->properties['requiredWithPropertyDefault']->isConstructorParameter);
        $this->assertFalse($class->properties['requiredWithPropertyDefault']->hasDefaultValue);
        $this->assertTrue($class->properties['normalizedName']->isConstructorParameter);
        $this->assertTrue($class->properties['normalizedName']->hasDefaultValue);
        $this->assertFalse($class->properties['unbound']->isConstructorParameter);
        $this->assertTrue($class->properties['unbound']->hasDefaultValue);
        $this->assertTrue($class->plainTransform);
    }

    /**
     * Test iterable annotation precedence and declaration scopes.
     */
    public function testIterableAnnotationsUseTheNearestOwningDeclaration(): void
    {
        $class = $this->factory()->build(new ReflectionClass(ChildAnnotations::class));

        $this->assertSame(ParentClassItem::class, $this->iterableItemName($class->properties['parentOnly']));
        $this->assertSame(ChildClassItem::class, $this->iterableItemName($class->properties['classItems']));
        $this->assertSame(InlineItem::class, $this->iterableItemName($class->properties['inlineItems']));
        $this->assertSame(ConstructorItem::class, $this->iterableItemName($class->properties['constructorItems']));
        $this->assertSame(ChildAnnotations::class, $class->dataIterablePropertyAnnotations['constructorItems'][0]->declaringClass);
    }

    /**
     * Test contextual ownership for promoted and constructor-only parameters.
     */
    public function testContextualParametersUseOneUnambiguousOwnershipForm(): void
    {
        $promoted = $this->factory()->build(new ReflectionClass(PromotedContextualDataFixture::class));
        $constructorOnly = $this->factory()->build(new ReflectionClass(ConstructorOnlyContextualDataFixture::class));

        $this->assertTrue($promoted->properties['userId']->isConstructorParameter);
        $this->assertFalse($promoted->properties['userId']->validate);
        $this->assertSame(ContextualValue::class, $promoted->constructorParameters[0]->contextualAttribute?->getName());
        $this->assertFalse($constructorOnly->properties['name']->isConstructorParameter);
        $this->assertTrue($constructorOnly->properties['name']->validate);
        $this->assertSame('userId', $constructorOnly->constructorParameters[0]->name);
    }

    /**
     * Test invalid constructor/property ownership declarations.
     *
     * @param class-string $class
     */
    #[DataProvider('invalidDeclarationProvider')]
    public function testInvalidConstructorDeclarationsFailDuringMetadataBuild(
        string $class,
        string $message,
    ): void {
        $this->expectException(InvalidDataDeclaration::class);
        $this->expectExceptionMessage($message);

        $this->factory()->build(new ReflectionClass($class));
    }

    /**
     * Provide invalid constructor declarations.
     */
    public static function invalidDeclarationProvider(): array
    {
        return [
            'unbound readonly property' => [UnboundReadonlyDataFixture::class, 'cannot assign unbound readonly property'],
            'computed constructor property' => [ComputedConstructorDataFixture::class, 'declares output-only property'],
            'contextual property collision' => [ContextualCollisionDataFixture::class, 'conflicts with public data property'],
            'non-public promoted property' => [NonPublicPromotedDataFixture::class, 'promotes non-public property'],
            'constructor parameter without property' => [MissingPropertyDataFixture::class, 'has no corresponding public data property'],
        ];
    }

    /**
     * Test duplicate effective mapping ownership.
     *
     * @param class-string $class
     */
    #[DataProvider('mappingCollisionProvider')]
    public function testMappingCollisionsFailDuringMetadataBuild(string $class, string $message): void
    {
        $this->expectException(InvalidDataDeclaration::class);
        $this->expectExceptionMessage($message);

        $this->factory()->build(new ReflectionClass($class));
    }

    /**
     * Provide duplicate mapping declarations.
     */
    public static function mappingCollisionProvider(): array
    {
        return [
            'input' => [DuplicateInputDataFixture::class, 'both resolve to input path [first]'],
            'output' => [DuplicateOutputDataFixture::class, 'both resolve to output key [first]'],
        ];
    }

    /**
     * Test allowed mapping overlap and hidden output ownership.
     */
    public function testPrefixOverlapAndHiddenOutputMappingsRemainValid(): void
    {
        $class = $this->factory()->build(new ReflectionClass(AllowedMappingDataFixture::class));

        $this->assertSame('artist', $class->properties['artist']->inputMappedName);
        $this->assertSame('artist.name', $class->properties['artistName']->inputMappedName);
        $this->assertTrue($class->properties['hiddenArtist']->hidden);
        $this->assertSame([], $class->outputMappedProperties);
    }

    /**
     * Test non-public constructors remain valid metadata.
     */
    public function testPrivateConstructorIsAValidNamedFactoryOnlyDeclaration(): void
    {
        $class = $this->factory()->build(new ReflectionClass(PrivateConstructorDataFixture::class));

        $this->assertNotNull($class->constructor);
        $this->assertTrue($class->constructor?->isPrivate());
        $this->assertTrue($class->properties['name']->isConstructorParameter);
        $this->assertArrayHasKey('fromString', $class->methods);
    }

    /**
     * Create the metadata factory with boot-stable collaborators.
     */
    protected function factory(array $overrides = []): DataClassFactory
    {
        $defaults = require __DIR__ . '/../../../src/data/config/data.php';
        $config = new DataConfig(new Repository([
            'data' => array_replace($defaults, $overrides),
        ]));
        $nameMapperResolver = new NameMapperResolver(new Container);
        $typeFactory = new DataTypeFactory(new PhpDocTypeNameResolver);
        $parameterFactory = new DataParameterFactory($typeFactory);

        return new DataClassFactory(
            new DataPropertyFactory($typeFactory, $config, $nameMapperResolver),
            new DataMethodFactory($parameterFactory, $typeFactory),
            $parameterFactory,
            new DataIterableAnnotationReader,
            $nameMapperResolver,
            $config,
        );
    }

    /**
     * Get the one item type compiled for an iterable property.
     */
    protected function iterableItemName(DataProperty $property): string
    {
        $iterableType = $property->type->getIterableTypes()[0];

        $this->assertNotNull($iterableType->iterableItemType);

        return $iterableType->iterableItemType->getNamedTypes()[0]->name;
    }
}

#[MapName(SnakeCaseMapper::class)]
#[MergeValidationRules]
#[FailOnUnknownFields]
#[StopOnFirstFailure]
#[ErrorBag('metadata')]
#[RedirectTo('/metadata')]
#[RedirectToRoute('metadata.store')]
class DataClassMetadataFixture
{
    /**
     * Create a new metadata fixture.
     */
    public function __construct(
        public string $firstName,
        public string $lastName = 'Doe',
    ) {
    }

    /**
     * Create the fixture from a string.
     */
    public static function fromString(string $name): static
    {
        throw new RuntimeException($name);
    }

    /**
     * Collect fixture values.
     */
    public static function collectStrings(array $items): array
    {
        return $items;
    }

    /**
     * Get fixture validation rules.
     */
    public static function rules(): array
    {
        return [];
    }
}

class ConstructorBindingDataFixture
{
    public readonly string $readonlyName;

    public string $requiredWithPropertyDefault = 'property-default';

    public string $normalizedName;

    public string $unbound = 'unbound';

    /**
     * Create a new constructor-binding fixture.
     */
    public function __construct(
        string $readonlyName,
        string $requiredWithPropertyDefault,
        string $normalizedName = 'constructor-default',
    ) {
        $this->readonlyName = $readonlyName;
        $this->requiredWithPropertyDefault = $requiredWithPropertyDefault;
        $this->normalizedName = strtoupper($normalizedName);
    }
}

class PromotedContextualDataFixture
{
    /**
     * Create a new promoted contextual fixture.
     */
    public function __construct(
        #[ContextualValue]
        public int $userId,
    ) {
    }
}

class ConstructorOnlyContextualDataFixture
{
    public string $name = 'Taylor';

    /**
     * Create a new constructor-only contextual fixture.
     */
    public function __construct(
        #[ContextualValue]
        int $userId,
    ) {
    }
}

class UnboundReadonlyDataFixture
{
    public readonly string $name;
}

class ComputedConstructorDataFixture
{
    /**
     * Create a new computed constructor fixture.
     */
    public function __construct(
        #[Computed]
        public string $slug,
    ) {
    }
}

class ContextualCollisionDataFixture
{
    public int $authorId;

    /**
     * Create a new contextual collision fixture.
     */
    public function __construct(
        #[ContextualValue]
        int $authorId,
    ) {
        $this->authorId = $authorId;
    }
}

class NonPublicPromotedDataFixture
{
    /**
     * Create a new non-public promoted fixture.
     */
    public function __construct(
        protected string $secret,
    ) {
    }
}

class MissingPropertyDataFixture
{
    /**
     * Create a new missing-property fixture.
     */
    public function __construct(string $source)
    {
    }
}

class DuplicateInputDataFixture
{
    public string $first;

    #[MapInputName('first')]
    public string $second;
}

class DuplicateOutputDataFixture
{
    public string $first;

    #[MapOutputName('first')]
    public string $second;
}

class AllowedMappingDataFixture
{
    #[MapInputName('artist')]
    public string $artist;

    #[MapInputName('artist.name')]
    public string $artistName;

    #[Hidden]
    #[MapOutputName('artist')]
    public string $hiddenArtist;
}

class PrivateConstructorDataFixture
{
    public readonly string $name;

    /**
     * Create a new private-constructor fixture.
     */
    private function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Create the fixture from a string.
     */
    public static function fromString(string $name): static
    {
        return new static($name);
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
class ContextualValue implements ContextualAttribute
{
}
