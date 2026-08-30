<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\Serializer;
use Hypervel\JsonSchema\Types\AnyOfType;
use Hypervel\JsonSchema\Types\ArrayType;
use Hypervel\JsonSchema\Types\BooleanType;
use Hypervel\JsonSchema\Types\IntegerType;
use Hypervel\JsonSchema\Types\NumberType;
use Hypervel\JsonSchema\Types\ObjectType;
use Hypervel\JsonSchema\Types\StringType;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\JsonSchema\Types\UnionType;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class SerializerTest extends TestCase
{
    #[DataProvider('supportedTypeSubclassProvider')]
    public function testItSerializesSubclassesOfSupportedTypes(Type $type, array $expected): void
    {
        $this->assertSame($expected, Serializer::serialize($type));
    }

    public static function supportedTypeSubclassProvider(): array
    {
        return [
            'anyOf' => [new class([new StringType]) extends AnyOfType {
            }, ['anyOf' => [['type' => 'string']]]],
            'array' => [new class extends ArrayType {
            }, ['type' => 'array']],
            'boolean' => [new class extends BooleanType {
            }, ['type' => 'boolean']],
            'integer' => [new class extends IntegerType {
            }, ['type' => 'integer']],
            'number' => [new class extends NumberType {
            }, ['type' => 'number']],
            'object' => [new class extends ObjectType {
            }, ['type' => 'object']],
            'string' => [new class extends StringType {
            }, ['type' => 'string']],
            'union' => [new class(['string', 'integer']) extends UnionType {
            }, ['type' => ['string', 'integer']]],
        ];
    }

    public function testItSerializesSchemaKeywordsDeclaredByATypeSubclass(): void
    {
        $type = new class extends StringType {
            protected string $contentEncoding = 'base64';
        };

        $schema = Serializer::serialize($type);

        $this->assertSame('string', $schema['type']);
        $this->assertSame('base64', $schema['contentEncoding']);
    }

    public function testSerializerSubclassesMayExtendTheIgnoredPropertySet(): void
    {
        $type = new class extends StringType {
            protected string $contentEncoding = 'base64';

            protected string $internalState = 'hidden';
        };

        $serializer = new class extends Serializer {
            protected static array $ignore = ['required', 'nullable', 'hasDefault', 'internalState'];
        };

        $this->assertSame([
            'contentEncoding' => 'base64',
            'type' => 'string',
        ], $serializer::serialize($type));
    }

    public function testItDoesNotKnowHowToSerializeUnknownTypes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported [Hypervel\JsonSchema\Types\Type@anonymous');

        $type = new class extends Type {
            // anonymous type for triggering serializer failure
        };

        $type->toArray();
    }
}
