<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent;

use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class UseEloquentBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clear the static cache between tests
        UseEloquentBuilderTestModel::clearResolvedBuilderClasses();
        UseEloquentBuilderTestModelWithAttribute::clearResolvedBuilderClasses();
        UseEloquentBuilderTestChildModel::clearResolvedBuilderClasses();
        UseEloquentBuilderTestChildModelWithOwnAttribute::clearResolvedBuilderClasses();

        parent::tearDown();
    }

    public function testNewModelBuilderReturnsDefaultBuilderWhenNoAttribute(): void
    {
        $model = new UseEloquentBuilderTestModel();
        $query = m::mock(\Hypervel\Database\Query\Builder::class);

        $builder = $model->newEloquentBuilder($query);

        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertNotInstanceOf(CustomTestBuilder::class, $builder);
    }

    public function testNewModelBuilderReturnsCustomBuilderWhenAttributePresent(): void
    {
        $model = new UseEloquentBuilderTestModelWithAttribute();
        $query = m::mock(\Hypervel\Database\Query\Builder::class);

        $builder = $model->newEloquentBuilder($query);

        $this->assertInstanceOf(CustomTestBuilder::class, $builder);
    }

    public function testNewModelBuilderCachesResolvedBuilderClass(): void
    {
        $model1 = new UseEloquentBuilderTestModelWithAttribute();
        $model2 = new UseEloquentBuilderTestModelWithAttribute();
        $query = m::mock(\Hypervel\Database\Query\Builder::class);

        // First call should resolve and cache
        $builder1 = $model1->newEloquentBuilder($query);

        // Second call should use cache
        $builder2 = $model2->newEloquentBuilder($query);

        // Both should be CustomTestBuilder
        $this->assertInstanceOf(CustomTestBuilder::class, $builder1);
        $this->assertInstanceOf(CustomTestBuilder::class, $builder2);
    }

    public function testResolveCustomBuilderClassReturnsFalseWhenNoAttribute(): void
    {
        $model = new UseEloquentBuilderTestModel();

        $result = $model->testResolveCustomBuilderClass();

        $this->assertFalse($result);
    }

    public function testResolveCustomBuilderClassReturnsBuilderClassWhenAttributePresent(): void
    {
        $model = new UseEloquentBuilderTestModelWithAttribute();

        $result = $model->testResolveCustomBuilderClass();

        $this->assertSame(CustomTestBuilder::class, $result);
    }

    public function testDifferentModelsUseDifferentCaches(): void
    {
        $modelWithoutAttribute = new UseEloquentBuilderTestModel();
        $modelWithAttribute = new UseEloquentBuilderTestModelWithAttribute();
        $query = m::mock(\Hypervel\Database\Query\Builder::class);

        $builder1 = $modelWithoutAttribute->newEloquentBuilder($query);
        $builder2 = $modelWithAttribute->newEloquentBuilder($query);

        $this->assertInstanceOf(Builder::class, $builder1);
        $this->assertNotInstanceOf(CustomTestBuilder::class, $builder1);
        $this->assertInstanceOf(CustomTestBuilder::class, $builder2);
    }

    public function testChildModelWithoutAttributeUsesDefaultBuilder(): void
    {
        $model = new UseEloquentBuilderTestChildModel();
        $query = m::mock(\Hypervel\Database\Query\Builder::class);

        $builder = $model->newEloquentBuilder($query);

        // PHP attributes are not inherited - child needs its own attribute
        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertNotInstanceOf(CustomTestBuilder::class, $builder);
    }

    public function testChildModelWithOwnAttributeUsesOwnBuilder(): void
    {
        $model = new UseEloquentBuilderTestChildModelWithOwnAttribute();
        $query = m::mock(\Hypervel\Database\Query\Builder::class);

        $builder = $model->newEloquentBuilder($query);

        $this->assertInstanceOf(AnotherCustomTestBuilder::class, $builder);
    }
}

// Test fixtures

class UseEloquentBuilderTestModel extends Model
{
    protected ?string $table = 'test_models';

    /**
     * Expose protected method for testing.
     */
    public function testResolveCustomBuilderClass(): string|false
    {
        return $this->resolveCustomBuilderClass();
    }

    /**
     * Clear the static cache for testing.
     */
    public static function clearResolvedBuilderClasses(): void
    {
        static::$resolvedBuilderClasses = [];
    }
}

#[UseEloquentBuilder(CustomTestBuilder::class)]
class UseEloquentBuilderTestModelWithAttribute extends Model
{
    protected ?string $table = 'test_models';

    /**
     * Expose protected method for testing.
     */
    public function testResolveCustomBuilderClass(): string|false
    {
        return $this->resolveCustomBuilderClass();
    }

    /**
     * Clear the static cache for testing.
     */
    public static function clearResolvedBuilderClasses(): void
    {
        static::$resolvedBuilderClasses = [];
    }
}

class UseEloquentBuilderTestChildModel extends UseEloquentBuilderTestModelWithAttribute
{
    /**
     * Clear the static cache for testing.
     */
    public static function clearResolvedBuilderClasses(): void
    {
        static::$resolvedBuilderClasses = [];
    }
}

#[UseEloquentBuilder(AnotherCustomTestBuilder::class)]
class UseEloquentBuilderTestChildModelWithOwnAttribute extends UseEloquentBuilderTestModelWithAttribute
{
    /**
     * Clear the static cache for testing.
     */
    public static function clearResolvedBuilderClasses(): void
    {
        static::$resolvedBuilderClasses = [];
    }
}

/**
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class CustomTestBuilder extends Builder
{
}

/**
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class AnotherCustomTestBuilder extends Builder
{
}
