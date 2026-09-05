<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

use Attribute;
use DateTimeImmutable;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\ContextualAttribute;
use Hypervel\Data\Attributes\AutoLazy;
use Hypervel\Data\Attributes\Computed;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\Hidden;
use Hypervel\Data\Attributes\LoadRelation;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\MapName;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Attributes\MergeValidationRules;
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Attributes\WithTransformer;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Enums\DataPropertyOperation;
use Hypervel\Data\Exceptions\InvalidDataDeclaration;
use Hypervel\Data\Lazy;
use Hypervel\Data\Mappers\SnakeCaseMapper;
use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Factories\DataClassFactory;
use Hypervel\Data\Support\Factories\DataMethodFactory;
use Hypervel\Data\Support\Factories\DataParameterFactory;
use Hypervel\Data\Support\Factories\DataPropertyFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Data\Transformers\Transformer;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Http\Attributes\ErrorBag;
use Hypervel\Foundation\Http\Attributes\FailOnUnknownFields;
use Hypervel\Foundation\Http\Attributes\RedirectTo;
use Hypervel\Foundation\Http\Attributes\RedirectToRoute;
use Hypervel\Foundation\Http\Attributes\StopOnFirstFailure;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\ChildScope\ChildAnnotations;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ChildClassItem;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ConstructorItem;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\InlineItem;
use Hypervel\Tests\Data\Fixtures\DataClassAnnotations\Items\ParentClassItem;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use stdClass;

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
        $this->assertFalse($class->bulkCopyTransformation);
        $this->assertNotNull($class->transformationRecipe);
        $this->assertNotNull($class->creationRecipe);
        $this->assertTrue($class->directConstructorInstantiation);
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
        $this->assertTrue($class->bulkCopyTransformation);
        $this->assertNull($class->transformationRecipe);
    }

    /**
     * Test backed set-only hooks remain valid data properties.
     */
    public function testBackedSetOnlyHookRemainsValid(): void
    {
        $class = $this->factory()->build(new ReflectionClass(BackedSetOnlyDataFixture::class));

        $this->assertFalse($class->properties['name']->computed);
        $this->assertFalse($class->properties['name']->hasGetHook);
    }

    /**
     * Test lean recipes retain ordered property operations and exact construction targets.
     */
    public function testLeanRecipesCompileOrderedPropertyMetadata(): void
    {
        $class = $this->factory()->build(new ReflectionClass(RecipeMetadataDataFixture::class));

        $this->assertSame(
            ['id', 'status', 'createdAt', 'child', 'displayName', 'secret'],
            array_column($class->creationRecipe?->properties ?? [], 'name'),
        );
        $this->assertSame([
            ['id', DataPropertyOperation::Builtin, 'int'],
            ['status', DataPropertyOperation::Enum, RecipeMetadataStatus::class],
            ['createdAt', DataPropertyOperation::Date, DateTimeImmutable::class],
            ['child', DataPropertyOperation::Data, RecipeMetadataChildFixture::class],
            ['displayName', DataPropertyOperation::Builtin, 'string'],
            ['secret', DataPropertyOperation::Builtin, 'string'],
        ], array_map(
            static fn (DataProperty $property): array => [
                $property->name,
                $property->constructionOperation,
                $property->constructionTarget,
            ],
            $class->creationRecipe?->properties ?? [],
        ));

        $this->assertSame(
            ['id', 'status', 'createdAt', 'child', 'displayName'],
            array_column($class->transformationRecipe?->properties ?? [], 'name'),
        );
        $this->assertSame([
            DataPropertyOperation::Copy,
            DataPropertyOperation::Enum,
            DataPropertyOperation::Date,
            DataPropertyOperation::Data,
            DataPropertyOperation::Copy,
        ], array_column($class->transformationRecipe?->properties ?? [], 'transformationOperation'));
        $this->assertFalse($class->bulkCopyTransformation);

        $arbitraryObject = $this->factory()->build(new ReflectionClass(ArbitraryObjectDataFixture::class));

        $this->assertNotNull($arbitraryObject->creationRecipe);
        $this->assertSame(DataPropertyOperation::Copy, $arbitraryObject->properties['value']->constructionOperation);
        $this->assertFalse($arbitraryObject->bulkCopyTransformation);
        $this->assertNull($arbitraryObject->transformationRecipe);
    }

    /**
     * Test bulk-copy metadata depends on the complete transformation classifier.
     */
    public function testBulkCopyMetadataDistinguishesArrayAndExtensionShapes(): void
    {
        $plainArray = $this->factory()->build(new ReflectionClass(PlainArrayTransformationFixture::class));
        $transformed = $this->factory()->build(new ReflectionClass(PropertyTransformerDataFixture::class));
        $annotated = $this->factory()->build(new ReflectionClass(AnnotatedDataArrayFixture::class));

        $this->assertTrue($plainArray->bulkCopyTransformation);
        $this->assertNull($plainArray->transformationRecipe);
        $this->assertFalse($transformed->bulkCopyTransformation);
        $this->assertNull($transformed->transformationRecipe);
        $this->assertFalse($annotated->bulkCopyTransformation);
        $this->assertNull($annotated->transformationRecipe);
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
        $defaultedConstructorOnly = $this->factory()->build(
            new ReflectionClass(DefaultedConstructorOnlyContextualDataFixture::class),
        );

        $this->assertTrue($promoted->properties['userId']->isConstructorParameter);
        $this->assertFalse($promoted->properties['userId']->validate);
        $this->assertSame(ContextualValue::class, $promoted->constructorParameters[0]->contextualAttribute?->getName());
        $this->assertSame(['userId' => true], $promoted->contextualParameters);
        $this->assertNull($promoted->creationRecipe);
        $this->assertFalse($promoted->directConstructorInstantiation);
        $this->assertFalse($constructorOnly->properties['name']->isConstructorParameter);
        $this->assertTrue($constructorOnly->properties['name']->validate);
        $this->assertSame('userId', $constructorOnly->constructorParameters[0]->name);
        $this->assertSame(['userId' => true], $constructorOnly->contextualParameters);
        $this->assertNull($constructorOnly->creationRecipe);
        $this->assertFalse($constructorOnly->directConstructorInstantiation);
        $this->assertSame(['userId' => true], $defaultedConstructorOnly->contextualParameters);
        $this->assertNull($defaultedConstructorOnly->creationRecipe);
        $this->assertFalse($defaultedConstructorOnly->directConstructorInstantiation);
    }

    /**
     * Test direct array creation requires a fixed array-safe class shape.
     */
    public function testCreationRecipeEligibilityUsesCompiledClassAndPropertyFacts(): void
    {
        $this->assertNotNull(
            $this->factory()->build(new ReflectionClass(DirectArrayCreationDataFixture::class))->creationRecipe,
        );

        foreach ([
            AbstractDirectArrayCreationDataFixture::class,
            MorphableDirectArrayCreationDataFixture::class,
            ClassNormalizerDirectArrayCreationDataFixture::class,
            PromotedContextualDataFixture::class,
            AutoLazyDirectArrayCreationDataFixture::class,
            LoadRelationDirectArrayCreationDataFixture::class,
            AttributeCastDirectArrayCreationDataFixture::class,
            DataCollectableDirectArrayCreationDataFixture::class,
            TypedIterableDirectArrayCreationDataFixture::class,
        ] as $class) {
            $this->assertNull(
                $this->factory()->build(new ReflectionClass($class))->creationRecipe,
                $class,
            );
        }
    }

    /**
     * Test configured creation extensions disable the direct array path.
     */
    public function testCreationRecipeEligibilityUsesBootConfiguration(): void
    {
        $configuredCast = $this->factory([
            'casts' => ['string' => DirectArrayCreationCast::class],
        ])->build(new ReflectionClass(DirectArrayCreationDataFixture::class));
        $configuredNormalizer = $this->factory([
            'normalizers' => [DirectArrayCreationNormalizer::class],
        ])->build(new ReflectionClass(DirectArrayCreationDataFixture::class));

        $this->assertNull($configuredCast->creationRecipe);
        $this->assertNull($configuredNormalizer->creationRecipe);
    }

    /**
     * Test direct constructor instantiation requires complete public constructor ownership.
     */
    public function testDirectConstructorInstantiationEligibilityUsesCompiledClassFacts(): void
    {
        $this->assertTrue(
            $this->factory()->build(new ReflectionClass(DirectArrayCreationDataFixture::class))
                ->directConstructorInstantiation,
        );
        $this->assertTrue(
            $this->factory()->build(new ReflectionClass(ComputedOnlyDataFixture::class))
                ->directConstructorInstantiation,
        );

        foreach ([
            AbstractDirectArrayCreationDataFixture::class,
            PrivateConstructorDataFixture::class,
            PromotedContextualDataFixture::class,
            ConstructorOnlyContextualDataFixture::class,
            BackedSetOnlyDataFixture::class,
        ] as $class) {
            $this->assertFalse(
                $this->factory()->build(new ReflectionClass($class))->directConstructorInstantiation,
                $class,
            );
        }
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
        $this->expectExceptionMessageIsOrContains($message);

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
            'write-only virtual property' => [WriteOnlyVirtualDataFixture::class, 'declares write-only virtual property'],
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
        $this->expectExceptionMessageIsOrContains($message);

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
     * Test Eloquent collection properties accept guaranteed model items.
     */
    public function testEloquentCollectionPropertiesAcceptModelItems(): void
    {
        $class = $this->factory()->build(new ReflectionClass(EloquentModelCollectionDataFixture::class));

        $this->assertSame(
            EloquentCollection::class,
            $class->properties['models']->type->getIterableTypes()[0]->name,
        );
    }

    /**
     * Test invalid Eloquent collection item declarations.
     *
     * @param class-string $class
     */
    #[DataProvider('invalidEloquentCollectionProvider')]
    public function testEloquentCollectionPropertiesRejectItemsThatDoNotGuaranteeModels(string $class): void
    {
        $this->expectException(InvalidDataDeclaration::class);
        $this->expectExceptionMessageIsOrContains('must guarantee');
        $this->expectExceptionMessageMatches('/' . preg_quote(Model::class, '/') . '/');

        $this->factory()->build(new ReflectionClass($class));
    }

    /**
     * Provide invalid Eloquent collection item declarations.
     */
    public static function invalidEloquentCollectionProvider(): array
    {
        return [
            'scalar' => [EloquentScalarCollectionDataFixture::class],
            'union' => [EloquentUnionCollectionDataFixture::class],
            'intersection' => [EloquentIntersectionCollectionDataFixture::class],
            'dnf' => [EloquentDnfCollectionDataFixture::class],
        ];
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

class BackedSetOnlyDataFixture
{
    public string $name = 'Taylor' {
        set {
            $this->name = strtoupper($value);
        }
    }
}

class DirectArrayCreationDataFixture extends Data
{
    /**
     * Create a new direct-array fixture.
     */
    public function __construct(public string $value = 'default')
    {
    }
}

enum RecipeMetadataStatus: string
{
    case Active = 'active';
}

class RecipeMetadataChildFixture extends Data
{
    /**
     * Create a new recipe metadata child fixture.
     */
    public function __construct(public int $id)
    {
    }
}

class RecipeMetadataDataFixture extends Data
{
    /**
     * Create a new recipe metadata fixture.
     */
    public function __construct(
        public int $id,
        public RecipeMetadataStatus $status,
        public DateTimeImmutable $createdAt,
        public RecipeMetadataChildFixture $child,
        #[MapOutputName('display_name')]
        public string $displayName,
        #[Hidden]
        public string $secret,
    ) {
    }
}

class ArbitraryObjectDataFixture extends Data
{
    /**
     * Create a new arbitrary-object fixture.
     */
    public function __construct(public stdClass $value)
    {
    }
}

class PlainArrayTransformationFixture extends Data
{
    public array $values = [];
}

class PropertyTransformerDataFixture extends Data
{
    #[WithTransformer(DataClassTransformerFixture::class)]
    public string $value = 'value';
}

class AnnotatedDataArrayFixture extends Data
{
    /** @var list<RecipeMetadataChildFixture> */
    public array $values = [];
}

class DataClassTransformerFixture implements Transformer
{
    /**
     * Transform the fixture value.
     */
    public function transform(
        DataProperty $property,
        mixed $value,
        TransformationContext $context,
    ): mixed {
        return $value;
    }
}

abstract class AbstractDirectArrayCreationDataFixture extends DirectArrayCreationDataFixture
{
}

class MorphableDirectArrayCreationDataFixture extends DirectArrayCreationDataFixture implements PropertyMorphableData
{
    /**
     * Resolve the concrete fixture class.
     */
    public static function morph(array $properties): ?string
    {
        return static::class;
    }
}

class ClassNormalizerDirectArrayCreationDataFixture extends DirectArrayCreationDataFixture
{
    /**
     * Get the class-owned normalizers.
     */
    public static function normalizers(): array
    {
        return [DirectArrayCreationNormalizer::class];
    }
}

class AutoLazyDirectArrayCreationDataFixture extends Data
{
    /**
     * Create a new automatic-lazy fixture.
     */
    public function __construct(
        #[AutoLazy]
        public string|Lazy $value,
    ) {
    }
}

class LoadRelationDirectArrayCreationDataFixture extends DirectArrayCreationDataFixture
{
    #[LoadRelation]
    public string $relation;
}

class AttributeCastDirectArrayCreationDataFixture extends DirectArrayCreationDataFixture
{
    #[WithCast(DirectArrayCreationCast::class)]
    public string $castValue;
}

class DataCollectableDirectArrayCreationDataFixture extends Data
{
    /**
     * Create a new data-collectable fixture.
     */
    public function __construct(
        #[DataCollectionOf(DirectArrayCreationDataFixture::class)]
        public DataCollection $items,
    ) {
    }
}

class TypedIterableDirectArrayCreationDataFixture extends Data
{
    /** @var list<string> */
    public array $items;
}

class DirectArrayCreationCast implements Cast
{
    /**
     * Cast the fixture value.
     */
    public function cast(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): mixed {
        return $value;
    }
}

class DirectArrayCreationNormalizer implements Normalizer
{
    /**
     * Normalize the fixture value.
     */
    public function normalize(mixed $value): array|Normalized|null
    {
        return null;
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

class DefaultedConstructorOnlyContextualDataFixture
{
    public string $name = 'Taylor';

    /**
     * Create a new defaulted constructor-only contextual fixture.
     */
    public function __construct(
        #[ContextualValue]
        ?int $userId = null,
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

class ComputedOnlyDataFixture
{
    public string $slug {
        get => 'computed';
    }
}

class WriteOnlyVirtualDataFixture
{
    public string $secret {
        set {
        }
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

class DataClassEloquentModel extends Model implements DataClassEloquentMarker
{
}

interface DataClassEloquentMarker
{
}

interface DataClassEloquentOtherMarker
{
}

class EloquentModelCollectionDataFixture
{
    /** @var EloquentCollection<int, DataClassEloquentMarker&DataClassEloquentModel> */
    public EloquentCollection $models;
}

class EloquentScalarCollectionDataFixture
{
    /** @var EloquentCollection<int, string> */
    public EloquentCollection $models;
}

class EloquentUnionCollectionDataFixture
{
    /** @var EloquentCollection<int, DataClassEloquentModel|string> */
    public EloquentCollection $models;
}

class EloquentIntersectionCollectionDataFixture
{
    /** @var EloquentCollection<int, DataClassEloquentMarker&DataClassEloquentOtherMarker> */
    public EloquentCollection $models;
}

class EloquentDnfCollectionDataFixture
{
    /** @var EloquentCollection<int, (DataClassEloquentMarker&DataClassEloquentModel)|string> */
    public EloquentCollection $models;
}

#[Attribute(Attribute::TARGET_PARAMETER)]
class ContextualValue implements ContextualAttribute
{
}
