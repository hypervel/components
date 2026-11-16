<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Transformers;

use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Transformers\SerializeTransformer;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class SerializeTransformerTest extends TestCase
{
    use VerifiesDoubles;

    public function testCanTransformUsingASerializer(): void
    {
        $transformer = new SerializeTransformer;

        $this->assertSame(
            serialize(DummyBackedEnum::FOO),
            $transformer->transform(Double::for(DataProperty::class), DummyBackedEnum::FOO, new TransformationContext)
        );
    }
}
