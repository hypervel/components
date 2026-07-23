<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent;

use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\HasBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Testbench\TestCase;

class HasBuilderTest extends TestCase
{
    /**
     * Assert string model keys use the configured custom builder for restoration.
     */
    public function testStringKeyRestorationUsesCustomBuilder(): void
    {
        $id = '018f89b4-8c37-7af8-a207-cc7ca9781383';

        $builder = (new HasBuilderTestModel)->newQueryForRestoration($id);

        $this->assertInstanceOf(HasBuilderTestBuilder::class, $builder);
        $this->assertSame([
            [
                'type' => 'Basic',
                'column' => 'has_builder_test_models.id',
                'operator' => '=',
                'value' => $id,
                'boolean' => 'and',
            ],
        ], $builder->getQuery()->wheres);
    }
}

#[UseEloquentBuilder(HasBuilderTestBuilder::class)]
class HasBuilderTestModel extends Model
{
    /** @use HasBuilder<HasBuilderTestBuilder<static>> */
    use HasBuilder;

    /**
     * The data type of the primary key ID.
     */
    protected string $keyType = 'string';
}

/**
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class HasBuilderTestBuilder extends Builder
{
}
