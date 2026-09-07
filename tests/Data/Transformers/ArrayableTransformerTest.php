<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Transformers;

use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Transformers\ArrayableTransformer;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use Mockery as m;

class ArrayableTransformerTest extends TestCase
{
    /**
     * Test an arrayable value is transformed to an array.
     */
    public function testTransformsAnArrayableValue(): void
    {
        $value = new Collection(['A', 'B']);

        $result = (new ArrayableTransformer)->transform(
            m::mock(DataProperty::class),
            $value,
            new TransformationContext,
        );

        $this->assertSame(['A', 'B'], $result);
        $this->assertSame(['A', 'B'], $value->all());
    }
}
