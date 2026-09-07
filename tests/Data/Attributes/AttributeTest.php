<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Attributes;

use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\MapInputName;
use Hypervel\Data\Attributes\MapName;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Attributes\WithCastable;
use Hypervel\Data\Attributes\WithCastAndTransformer;
use Hypervel\Data\Attributes\WithTransformer;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Casts\Castable;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Exceptions\CannotCreateCastAttribute;
use Hypervel\Data\Exceptions\CannotCreateTransformerAttribute;
use Hypervel\Data\Exceptions\CannotFindDataClass;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Transformers\Transformer;
use Hypervel\Tests\TestCase;
use stdClass;

class AttributeTest extends TestCase
{
    public function testMappingAttributesRetainTheirConfiguredNames(): void
    {
        $mapName = new MapName('input');

        $this->assertSame('input', $mapName->input);
        $this->assertSame('input', $mapName->output);
        $this->assertSame('input', (new MapName('input', 'output'))->input);
        $this->assertSame('output', (new MapName('input', 'output'))->output);
        $this->assertSame(10, (new MapInputName(10))->input);
        $this->assertSame(20, (new MapOutputName(20))->output);
    }

    public function testDataCollectionOfRequiresADataClass(): void
    {
        $this->assertSame(AttributeTestData::class, (new DataCollectionOf(AttributeTestData::class))->class);

        $this->expectException(CannotFindDataClass::class);

        new DataCollectionOf(stdClass::class);
    }

    public function testCastAttributesCreateFreshConfiguredExtensions(): void
    {
        $castAttribute = new WithCast(AttributeTestArgumentCast::class, 'cast');
        $castableAttribute = new WithCastable(AttributeTestCastable::class, 'castable');
        $transformerAttribute = new WithTransformer(AttributeTestArgumentTransformer::class, 'transformer');
        $combinedAttribute = new WithCastAndTransformer(AttributeTestCastAndTransformer::class, 'combined');

        $this->assertEquals(new AttributeTestArgumentCast('cast'), $castAttribute->get());
        $this->assertNotSame($castAttribute->get(), $castAttribute->get());
        $this->assertEquals(new AttributeTestArgumentCast('castable'), $castableAttribute->get());
        $this->assertEquals(new AttributeTestArgumentTransformer('transformer'), $transformerAttribute->get());
        $this->assertEquals(new AttributeTestCastAndTransformer('combined'), $combinedAttribute->get());
    }

    public function testCastAttributesRejectInvalidExtensionClasses(): void
    {
        try {
            new WithCast(stdClass::class);

            $this->fail('Expected an invalid cast class to be rejected.');
        } catch (CannotCreateCastAttribute $exception) {
            $this->assertStringContainsString(stdClass::class, $exception->getMessage());
        }

        $this->expectException(CannotCreateTransformerAttribute::class);

        new WithTransformer(stdClass::class);
    }
}

abstract class AttributeTestData implements BaseData
{
}

class AttributeTestArgumentCast implements Cast
{
    /** @var list<mixed> */
    public readonly array $arguments;

    /**
     * Create a new argument cast.
     */
    public function __construct(mixed ...$arguments)
    {
        $this->arguments = $arguments;
    }

    /**
     * Cast a property value.
     */
    public function cast(
        DataProperty $property,
        mixed $value,
        ConstructionState $state,
        CreationContext $context,
    ): mixed {
        return $value;
    }
}

class AttributeTestArgumentTransformer implements Transformer
{
    /** @var list<mixed> */
    public readonly array $arguments;

    /**
     * Create a new argument transformer.
     */
    public function __construct(mixed ...$arguments)
    {
        $this->arguments = $arguments;
    }

    /**
     * Transform a property value.
     */
    public function transform(
        DataProperty $property,
        mixed $value,
        TransformationContext $context,
    ): mixed {
        return $value;
    }
}

class AttributeTestCastAndTransformer extends AttributeTestArgumentCast implements Transformer
{
    /**
     * Transform a property value.
     */
    public function transform(
        DataProperty $property,
        mixed $value,
        TransformationContext $context,
    ): mixed {
        return $value;
    }
}

class AttributeTestCastable implements Castable
{
    /**
     * Create the cast for this type.
     */
    public static function dataCastUsing(array $arguments): Cast
    {
        return new AttributeTestArgumentCast(...$arguments);
    }
}
