<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Transformers;

use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Transformers\EnumTransformer;
use Hypervel\Tests\TestCase;
use Mockery as m;

class EnumTransformerTest extends TestCase
{
    public function testCanTransformEnums(): void
    {
        $transformer = new EnumTransformer;
        $property = m::mock(DataProperty::class);
        $context = new TransformationContext;

        $this->assertSame('ready', $transformer->transform($property, StringStatus::Ready, $context));
        $this->assertSame(2, $transformer->transform($property, IntegerStatus::Ready, $context));
    }
}

enum StringStatus: string
{
    case Ready = 'ready';
}

enum IntegerStatus: int
{
    case Ready = 2;
}
