<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Relations;

use Hypervel\Database\Eloquent\Attributes\CollectedBy;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Testbench\TestCase;

/**
 * Tests that Pivot and MorphPivot support custom collection classes
 * via the HasCollection trait and $collectionClass property.
 *
 * @internal
 * @coversNothing
 */
class PivotCollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clear the static cache between tests
        PivotCollectionTestPivot::clearResolvedCollectionClasses();
        PivotCollectionTestPivotWithAttribute::clearResolvedCollectionClasses();
        PivotCollectionTestPivotWithProperty::clearResolvedCollectionClasses();
        PivotCollectionTestPivotWithAttributeAndProperty::clearResolvedCollectionClasses();
        PivotCollectionTestMorphPivot::clearResolvedCollectionClasses();
        PivotCollectionTestMorphPivotWithAttribute::clearResolvedCollectionClasses();
        PivotCollectionTestMorphPivotWithProperty::clearResolvedCollectionClasses();
        PivotCollectionTestMorphPivotWithAttributeAndProperty::clearResolvedCollectionClasses();

        parent::tearDown();
    }

    // =========================================================================
    // Pivot Tests
    // =========================================================================

    public function testPivotNewCollectionReturnsHypervelCollectionByDefault(): void
    {
        $pivot = new PivotCollectionTestPivot();

        $collection = $pivot->newCollection([]);

        $this->assertInstanceOf(Collection::class, $collection);
    }

    public function testPivotNewCollectionReturnsCustomCollectionWhenAttributePresent(): void
    {
        $pivot = new PivotCollectionTestPivotWithAttribute();

        $collection = $pivot->newCollection([]);

        $this->assertInstanceOf(CustomPivotCollection::class, $collection);
    }

    public function testPivotNewCollectionUsesCollectionClassPropertyWhenNoAttribute(): void
    {
        $pivot = new PivotCollectionTestPivotWithProperty();

        $collection = $pivot->newCollection([]);

        $this->assertInstanceOf(PropertyPivotCollection::class, $collection);
    }

    public function testPivotAttributeTakesPrecedenceOverCollectionClassProperty(): void
    {
        $pivot = new PivotCollectionTestPivotWithAttributeAndProperty();

        $collection = $pivot->newCollection([]);

        // Attribute should win over property
        $this->assertInstanceOf(CustomPivotCollection::class, $collection);
        $this->assertNotInstanceOf(PropertyPivotCollection::class, $collection);
    }

    public function testPivotNewCollectionPassesModelsToCollection(): void
    {
        $pivot1 = new PivotCollectionTestPivot();
        $pivot2 = new PivotCollectionTestPivot();

        $collection = $pivot1->newCollection([$pivot1, $pivot2]);

        $this->assertCount(2, $collection);
        $this->assertSame($pivot1, $collection[0]);
        $this->assertSame($pivot2, $collection[1]);
    }

    // =========================================================================
    // MorphPivot Tests
    // =========================================================================

    public function testMorphPivotNewCollectionReturnsHypervelCollectionByDefault(): void
    {
        $pivot = new PivotCollectionTestMorphPivot();

        $collection = $pivot->newCollection([]);

        $this->assertInstanceOf(Collection::class, $collection);
    }

    public function testMorphPivotNewCollectionReturnsCustomCollectionWhenAttributePresent(): void
    {
        $pivot = new PivotCollectionTestMorphPivotWithAttribute();

        $collection = $pivot->newCollection([]);

        $this->assertInstanceOf(CustomMorphPivotCollection::class, $collection);
    }

    public function testMorphPivotNewCollectionUsesCollectionClassPropertyWhenNoAttribute(): void
    {
        $pivot = new PivotCollectionTestMorphPivotWithProperty();

        $collection = $pivot->newCollection([]);

        $this->assertInstanceOf(PropertyMorphPivotCollection::class, $collection);
    }

    public function testMorphPivotAttributeTakesPrecedenceOverCollectionClassProperty(): void
    {
        $pivot = new PivotCollectionTestMorphPivotWithAttributeAndProperty();

        $collection = $pivot->newCollection([]);

        // Attribute should win over property
        $this->assertInstanceOf(CustomMorphPivotCollection::class, $collection);
        $this->assertNotInstanceOf(PropertyMorphPivotCollection::class, $collection);
    }
}

// =========================================================================
// Pivot Test Fixtures
// =========================================================================

class PivotCollectionTestPivot extends Pivot
{
    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

#[CollectedBy(CustomPivotCollection::class)]
class PivotCollectionTestPivotWithAttribute extends Pivot
{
    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

class PivotCollectionTestPivotWithProperty extends Pivot
{
    protected static string $collectionClass = PropertyPivotCollection::class;

    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

#[CollectedBy(CustomPivotCollection::class)]
class PivotCollectionTestPivotWithAttributeAndProperty extends Pivot
{
    protected static string $collectionClass = PropertyPivotCollection::class;

    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

// =========================================================================
// MorphPivot Test Fixtures
// =========================================================================

class PivotCollectionTestMorphPivot extends MorphPivot
{
    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

#[CollectedBy(CustomMorphPivotCollection::class)]
class PivotCollectionTestMorphPivotWithAttribute extends MorphPivot
{
    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

class PivotCollectionTestMorphPivotWithProperty extends MorphPivot
{
    protected static string $collectionClass = PropertyMorphPivotCollection::class;

    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

#[CollectedBy(CustomMorphPivotCollection::class)]
class PivotCollectionTestMorphPivotWithAttributeAndProperty extends MorphPivot
{
    protected static string $collectionClass = PropertyMorphPivotCollection::class;

    public static function clearResolvedCollectionClasses(): void
    {
        static::$resolvedCollectionClasses = [];
    }
}

// =========================================================================
// Custom Collection Classes
// =========================================================================

/**
 * @template TKey of array-key
 * @template TModel
 * @extends Collection<TKey, TModel>
 */
class CustomPivotCollection extends Collection
{
}

/**
 * @template TKey of array-key
 * @template TModel
 * @extends Collection<TKey, TModel>
 */
class PropertyPivotCollection extends Collection
{
}

/**
 * @template TKey of array-key
 * @template TModel
 * @extends Collection<TKey, TModel>
 */
class CustomMorphPivotCollection extends Collection
{
}

/**
 * @template TKey of array-key
 * @template TModel
 * @extends Collection<TKey, TModel>
 */
class PropertyMorphPivotCollection extends Collection
{
}
