<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

use Hypervel\Config\Repository;
use Hypervel\Container\Attributes\Config;
use Hypervel\Container\Container;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\WithoutValidation;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Exceptions\CannotFindDataClass;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\DataClassRepository;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\Factories\DataClassFactory;
use Hypervel\Data\Support\Factories\DataMethodFactory;
use Hypervel\Data\Support\Factories\DataParameterFactory;
use Hypervel\Data\Support\Factories\DataPropertyFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Data\Support\Validation\ValidationContext;
use Hypervel\Tests\TestCase;

class DataClassRepositoryTest extends TestCase
{
    /**
     * Test metadata is analyzed once for each repository and data class.
     */
    public function testMetadataIsMemoizedPerRepository(): void
    {
        $repository = $this->repository();
        $first = $repository->get(RepositoryDataFixture::class);
        $second = $repository->get(RepositoryDataFixture::class);
        $otherRepository = $this->repository();

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $otherRepository->get(RepositoryDataFixture::class));
    }

    /**
     * Test recursive types retain class strings instead of recursive metadata.
     */
    public function testRecursiveDataTypesRemainFinite(): void
    {
        $repository = $this->repository();
        $class = $repository->get(RecursiveRepositoryDataFixture::class);
        $types = $class->properties['child']->type->getDataObjectTypes();

        $this->assertCount(1, $types);
        $this->assertSame(RecursiveRepositoryDataFixture::class, $types[0]->dataClass);
        $this->assertFalse($repository->hasDynamicRuleGraph(RecursiveRepositoryDataFixture::class));
        $this->assertFalse($repository->hasDynamicRuleGraph(RecursiveRepositoryDataFixture::class));
        $this->assertTrue($repository->hasDynamicRuleGraph(RecursiveDynamicRepositoryDataFixture::class));
        $this->assertTrue($repository->hasDynamicRuleGraph(RecursiveDynamicChildRepositoryDataFixture::class));
    }

    /**
     * Test dynamic rule graphs include unambiguous validated descendants.
     */
    public function testDynamicRuleGraphsIncludeValidatedDescendants(): void
    {
        $repository = $this->repository();

        $this->assertTrue($repository->hasDynamicRuleGraph(DynamicRepositoryDataFixture::class));
        $this->assertTrue($repository->hasDynamicRuleGraph(NestedDynamicRepositoryDataFixture::class));
        $this->assertTrue($repository->hasDynamicRuleGraph(CollectionDynamicRepositoryDataFixture::class));
    }

    /**
     * Test properties excluded from validation do not make a graph dynamic.
     */
    public function testDynamicRuleGraphsSkipNonValidatingProperties(): void
    {
        $repository = $this->repository();

        $this->assertFalse($repository->hasDynamicRuleGraph(SkippedDynamicRepositoryDataFixture::class));
        $this->assertFalse($repository->hasDynamicRuleGraph(ContextualDynamicRepositoryDataFixture::class));
    }

    /**
     * Test property morph selection makes a rule graph dynamic.
     */
    public function testPropertyMorphableRuleGraphsAreDynamic(): void
    {
        $this->assertTrue(
            $this->repository()->hasDynamicRuleGraph(MorphableRepositoryDataFixture::class),
        );
    }

    /**
     * Test only declared data classes enter the worker repository.
     */
    public function testInvalidClassesAreRejected(): void
    {
        $this->expectException(CannotFindDataClass::class);
        $this->expectExceptionMessageIsOrContains('must implement');

        $this->repository()->get(RepositoryInvalidFixture::class);
    }

    /**
     * Create a fresh repository and its worker-safe factory graph.
     */
    protected function repository(): DataClassRepository
    {
        $defaults = require __DIR__ . '/../../../src/data/config/data.php';
        $config = new DataConfig(new Repository(['data' => $defaults]));
        $nameMapperResolver = new NameMapperResolver(new Container);
        $typeFactory = new DataTypeFactory(new PhpDocTypeNameResolver);
        $parameterFactory = new DataParameterFactory($typeFactory);

        return new DataClassRepository(new DataClassFactory(
            new DataPropertyFactory($typeFactory, $config, $nameMapperResolver),
            new DataMethodFactory($parameterFactory, $typeFactory),
            $parameterFactory,
            new DataIterableAnnotationReader,
            $nameMapperResolver,
            $config,
        ));
    }
}

abstract class RepositoryDataFixture implements BaseData
{
    /**
     * Create a new repository fixture.
     */
    public function __construct(
        public string $name,
    ) {
    }
}

abstract class RecursiveRepositoryDataFixture implements BaseData
{
    /**
     * Create a new recursive repository fixture.
     */
    public function __construct(
        public ?self $child = null,
    ) {
    }
}

abstract class DynamicRepositoryDataFixture implements BaseData
{
    /**
     * Get payload-dependent validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return ['name' => ['in:' . ($context->payload['name'] ?? '')]];
    }
}

abstract class RecursiveDynamicRepositoryDataFixture implements BaseData
{
    /**
     * Create a recursive dynamic repository fixture.
     */
    public function __construct(
        public ?RecursiveDynamicChildRepositoryDataFixture $child = null,
    ) {
    }
}

abstract class RecursiveDynamicChildRepositoryDataFixture implements BaseData
{
    /**
     * Create a recursive dynamic child repository fixture.
     */
    public function __construct(
        public ?RecursiveDynamicRepositoryDataFixture $parent = null,
    ) {
    }

    /**
     * Get payload-dependent validation rules.
     */
    public static function rules(ValidationContext $context): array
    {
        return [];
    }
}

abstract class NestedDynamicRepositoryDataFixture implements BaseData
{
    /**
     * Create a nested dynamic repository fixture.
     */
    public function __construct(
        public DynamicRepositoryDataFixture $child,
    ) {
    }
}

abstract class CollectionDynamicRepositoryDataFixture implements BaseData
{
    /**
     * Create a collection dynamic repository fixture.
     *
     * @param array<array-key, DynamicRepositoryDataFixture> $children
     */
    public function __construct(
        #[DataCollectionOf(DynamicRepositoryDataFixture::class)]
        public array $children,
    ) {
    }
}

abstract class SkippedDynamicRepositoryDataFixture implements BaseData
{
    /**
     * Create a skipped dynamic repository fixture.
     */
    public function __construct(
        #[WithoutValidation]
        public DynamicRepositoryDataFixture $child,
    ) {
    }
}

abstract class ContextualDynamicRepositoryDataFixture implements BaseData
{
    /**
     * Create a contextual dynamic repository fixture.
     */
    public function __construct(
        #[Config('data.dynamic')]
        public DynamicRepositoryDataFixture $child,
    ) {
    }
}

abstract class MorphableRepositoryDataFixture implements BaseData, PropertyMorphableData
{
    /**
     * Resolve the selected morph class.
     */
    public static function morph(array $properties): ?string
    {
        return null;
    }
}

class RepositoryInvalidFixture
{
}
