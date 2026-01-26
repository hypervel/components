<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Concerns;

use Hypervel\Database\Eloquent\Attributes\CollectedBy;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Testbench\TestCase;

/**
 * @internal
 * @coversNothing
 */
class HasCollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clear the static cache between tests
        HasCollectionTestModel::clearResolvedCollectionClasses();
        HasCollectionTestModelWithAttribute::clearResolvedCollectionClasses();
        HasCollectionTestChildModel::clearResolvedCollectionClasses();
        HasCollectionTestChildModelWithOwnAttribute::clearResolvedCollectionClasses();
        HasCollectionTestModelWithProperty::clearResolvedCollectionClasses();
        HasCollectionTestModelWithAttributeAndProperty::clearResolvedCollectionClasses();

        parent::tearDown();
    }

    public function testNewCollectionReturnsDefaultCollectionWhenNoAttribute(): void
    {
        $model = new HasCollectionTestModel();

        $collection = $model->newCollection([]);

        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertNotInstanceOf(CustomTestCollection::class, $collection);
    }

    public function testNewCollectionReturnsCustomCollectionWhenAttributePresent(): void
    {
        $model = new HasCollectionTestModelWithAttribute();

        $collection = $model->newCollection([]);

        $this->assertInstanceOf(CustomTestCollection::class, $collection);
    }

    public function testNewCollectionPassesModelsToCollection(): void
    {
        $model1 = new HasCollectionTestModel();
        $model2 = new HasCollectionTestModel();

        $collection = $model1->newCollection([$model1, $model2]);

        $this->assertCount(2, $collection);
        $this->assertSame($model1, $collection[0]);
        $this->assertSame($model2, $collection[1]);
    }

    public function testNewCollectionCachesResolvedCollectionClass(): void
    {
        $model1 = new HasCollectionTestModelWithAttribute();
        $model2 = new HasCollectionTestModelWithAttribute();

        // First call should resolve and cache
        $collection1 = $model1->newCollection([]);

        // Second call should use cache
        $collection2 = $model2->newCollection([]);

        // Both should be CustomTestCollection
        $this->assertInstanceOf(CustomTestCollection::class, $collection1);
        $this->assertInstanceOf(CustomTestCollection::class, $collection2);
    }

    public function testResolveCollectionFromAttributeReturnsNullWhenNoAttribute(): void
    {
        $model = new HasCollectionTestModel();

        $result = $model->testResolveCollectionFromAttribute();

        $this->assertNull($result);
    }

    public function testResolveCollectionFromAttributeReturnsCollectionClassWhenAttributePresent(): void
    {
        $model = new HasCollectionTestModelWithAttribute();

        $result = $model->testResolveCollectionFromAttribute();

        $this->assertSame(CustomTestCollection::class, $result);
    }

    public function testDifferentModelsUseDifferentCaches(): void
    {
        $modelWithoutAttribute = new HasCollectionTestModel();
        $modelWithAttribute = new HasCollectionTestModelWithAttribute();

        $collection1 = $modelWithoutAttribute->newCollection([]);
        $collection2 = $modelWithAttribute->newCollection([]);

        $this->assertInstanceOf(Collection::class, $collection1);
        $this->assertNotInstanceOf(CustomTestCollection::class, $collection1);
        $this->assertInstanceOf(CustomTestCollection::class, $collection2);
    }

    public function testChildModelWithoutAttributeUsesDefaultCollection(): void
    {
        $model = new HasCollectionTestChildModel();

        $collection = $model->newCollection([]);

        // PHP attributes are not inherited - child needs its own attribute
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertNotInstanceOf(CustomTestCollection::class, $collection);
    }

    public function testChildModelWithOwnAttributeUsesOwnCollection(): void
    {
        $model = new HasCollectionTestChildModelWithOwnAttribute();

        $collection = $model->newCollection([]);

        $this->assertInstanceOf(AnotherCustomTestCollection::class, $collection);
    }

    public function testNewCollectionUsesCollectionClassPropertyWhenNoAttribute(): void
    {
        $model = new HasCollectionTestModelWithProperty();

        $collection = $model->newCollection([]);

        $this->assertInstanceOf(PropertyTestCollection::class, $collection);
    }

    public function testAttributeTakesPrecedenceOverCollectionClassProperty(): void
    {
        $model = new HasCollectionTestModelWithAttributeAndProperty();

        $collection = $model->newCollection([]);

        // Attribute should win over property
        $this->assertInstanceOf(CustomTestCollection::class, $collection);
        $this->assertNotInstanceOf(PropertyTestCollection::class, $collection);
    }
}

// Test fixtures

class HasCollectionTestModel extends Model
{
    protected ?string $table = 'test_models';

    /**
     * Expose protected method for testing.
     */
    public function testResolveCollectionFromAttribute(): ?string
    {
        return $this->resolveCollectionFromAttribute();
    }

    /**
     * Clear the static cache for testing.
     */
    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

#[CollectedBy(CustomTestCollection::class)]
class HasCollectionTestModelWithAttribute extends Model
{
    protected ?string $table = 'test_models';

    /**
     * Expose protected method for testing.
     */
    public function testResolveCollectionFromAttribute(): ?string
    {
        return $this->resolveCollectionFromAttribute();
    }

    /**
     * Clear the static cache for testing.
     */
    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

class HasCollectionTestChildModel extends HasCollectionTestModelWithAttribute
{
    /**
     * Clear the static cache for testing.
     */
    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

#[CollectedBy(AnotherCustomTestCollection::class)]
class HasCollectionTestChildModelWithOwnAttribute extends HasCollectionTestModelWithAttribute
{
    /**
     * Clear the static cache for testing.
     */
    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

/**
 * @template TKey of array-key
 * @template TModel of Model
 * @extends Collection<TKey, TModel>
 */
class CustomTestCollection extends Collection
{
}

/**
 * @template TKey of array-key
 * @template TModel of Model
 * @extends Collection<TKey, TModel>
 */
class AnotherCustomTestCollection extends Collection
{
}

class HasCollectionTestModelWithProperty extends Model
{
    protected ?string $table = 'test_models';

    protected static string $collectionClass = PropertyTestCollection::class;

    /**
     * Clear the static cache for testing.
     */
    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

#[CollectedBy(CustomTestCollection::class)]
class HasCollectionTestModelWithAttributeAndProperty extends Model
{
    protected ?string $table = 'test_models';

    // Property should be ignored when attribute is present
    protected static string $collectionClass = PropertyTestCollection::class;

    /**
     * Clear the static cache for testing.
     */
    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

/**
 * @template TKey of array-key
 * @template TModel of Model
 * @extends Collection<TKey, TModel>
 */
class PropertyTestCollection extends Collection
{
}
