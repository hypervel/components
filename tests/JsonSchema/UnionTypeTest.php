<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\Serializer;
use Hypervel\JsonSchema\Types\UnionType;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class UnionTypeTest extends TestCase
{
    public function testSerializesAsATypeArray(): void
    {
        $type = JsonSchema::union(['string', 'number', 'boolean']);

        $this->assertEquals([
            'type' => ['string', 'number', 'boolean'],
        ], $type->toArray());
    }

    public function testSerializesWithMetadata(): void
    {
        $type = JsonSchema::union(['string', 'number'])
            ->title('Value')
            ->description('A string or a number');

        $this->assertEquals([
            'type' => ['string', 'number'],
            'title' => 'Value',
            'description' => 'A string or a number',
        ], $type->toArray());
    }

    public function testDedupesAndPreservesMemberOrder(): void
    {
        $type = JsonSchema::union(['number', 'string', 'number', 'boolean', 'string']);

        $this->assertSame(['number', 'string', 'boolean'], $type->types());
        $this->assertSame(['type' => ['number', 'string', 'boolean']], $type->toArray());
    }

    public function testAppendsNullWhenNullable(): void
    {
        $type = JsonSchema::union(['string', 'number'])->nullable();

        $this->assertEquals([
            'type' => ['string', 'number', 'null'],
        ], $type->toArray());
    }

    public function testItNormalizesANullMemberIntoNullability(): void
    {
        $type = JsonSchema::union(['string', 'number', 'null']);

        $this->assertSame(['string', 'number'], $type->types());
        $this->assertEquals([
            'type' => ['string', 'number', 'null'],
        ], $type->toArray());
    }

    public function testANullOnlyUnionSerializesAsANullableUnion(): void
    {
        $this->assertSame([
            'type' => ['null'],
        ], JsonSchema::union(['null'])->toArray());
    }

    public function testItDoesNotDuplicateNullWhenAlreadyNullable(): void
    {
        $type = JsonSchema::union(['string', 'null'])->nullable();

        $this->assertEquals([
            'type' => ['string', 'null'],
        ], $type->toArray());
    }

    public function testEmptyUnionMayBecomeNullable(): void
    {
        $this->assertSame([
            'type' => ['null'],
        ], JsonSchema::union([])->nullable()->toArray());
    }

    public function testFinallyEmptyUnionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A JSON Schema union must contain at least one type.');

        JsonSchema::union([])->toArray();
    }

    public function testItDistinguishesAnExplicitNullDefaultFromAnUnsetDefault(): void
    {
        $this->assertArrayNotHasKey('default', JsonSchema::union(['string'])->toArray());
        $this->assertSame([
            'default' => null,
            'type' => ['string'],
        ], JsonSchema::union(['string'])->default(null)->toArray());
    }

    public function testItRejectsAnUnsupportedMemberName(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Unsupported JSON Schema type [wat] in a multi-type union.'));

        JsonSchema::union(['string', 'wat']);
    }

    #[DataProvider('nonStringMemberProvider')]
    public function testItRejectsANonStringMember(mixed $member): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Every JSON Schema union member must be a string.'));

        JsonSchema::union(['string', $member]);
    }

    public static function nonStringMemberProvider(): array
    {
        return [
            'integer' => [123],
            'array' => [['string']],
            'object' => [new stdClass],
            'null' => [null],
            'boolean' => [true],
        ];
    }

    public function testItRoundTripsAUnion(): void
    {
        $schema = ['type' => ['string', 'number', 'boolean']];

        $type = JsonSchema::fromArray($schema);

        $this->assertInstanceOf(UnionType::class, $type);
        $this->assertSame($schema, Serializer::serialize($type));
        $this->assertEquals($type, JsonSchema::fromArray(Serializer::serialize($type)));
    }

    public function testItRoundTripsANullableUnion(): void
    {
        $schema = ['type' => ['string', 'number', 'null']];

        $type = JsonSchema::fromArray($schema);

        $this->assertInstanceOf(UnionType::class, $type);
        $this->assertSame($schema, Serializer::serialize($type));
    }
}
