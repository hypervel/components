<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent;

use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class UseEloquentBuilderTest extends TestCase
{
    public function testNewModelBuilderReturnsDefaultBuilderWhenNoAttribute(): void
    {
        $model = new UseEloquentBuilderTestModel;
        $query = m::mock(\Hypervel\Database\Query\Builder::class);

        $builder = $model->newEloquentBuilder($query);

        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertNotInstanceOf(CustomTestBuilder::class, $builder);
    }

    public function testNewModelBuilderReturnsCustomBuilderWhenAttributePresent(): void
    {
        $model = new UseEloquentBuilderTestModelWithAttribute;
        $query = m::mock(\Hypervel\Database\Query\Builder::class);

        $builder = $model->newEloquentBuilder($query);

        $this->assertInstanceOf(CustomTestBuilder::class, $builder);
    }

    public function testNewModelBuilderCachesResolvedBuilderClass(): void
    {
        $model1 = new UseEloquentBuilderTestModelWithAttribute;
        $model2 = new UseEloquentBuilderTestModelWithAttribute;
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
        $model = new UseEloquentBuilderTestModel;

        $result = $model->testResolveCustomBuilderClass();

        $this->assertFalse($result);
    }

    public function testResolveCustomBuilderClassReturnsBuilderClassWhenAttributePresent(): void
    {
        $model = new UseEloquentBuilderTestModelWithAttribute;

        $result = $model->testResolveCustomBuilderClass();

        $this->assertSame(CustomTestBuilder::class, $result);
    }

    public function testDifferentModelsUseDifferentCaches(): void
    {
        $modelWithoutAttribute = new UseEloquentBuilderTestModel;
        $modelWithAttribute = new UseEloquentBuilderTestModelWithAttribute;
        $query = m::mock(\Hypervel\Database\Query\Builder::class);

        $builder1 = $modelWithoutAttribute->newEloquentBuilder($query);
        $builder2 = $modelWithAttribute->newEloquentBuilder($query);

        $this->assertInstanceOf(Builder::class, $builder1);
        $this->assertNotInstanceOf(CustomTestBuilder::class, $builder1);
        $this->assertInstanceOf(CustomTestBuilder::class, $builder2);
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
}

/**
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class CustomTestBuilder extends Builder
{
}
