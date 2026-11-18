<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Transformers;

use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Transformers\ArrayableTransformer;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class ArrayableTransformerTest extends TestCase
{
    use VerifiesDoubles;

    public function testCanTransformAnArrayable(): void
    {
        $value = new Collection(['A', 'B']);

        $result = (new ArrayableTransformer)->transform(
            Double::for(DataProperty::class)->strict(),
            $value,
            new TransformationContext,
        );

        $this->assertSame(['A', 'B'], $result);
        $this->assertSame(['A', 'B'], $value->all());
    }
}
