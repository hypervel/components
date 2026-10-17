<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Support;

use Hypervel\Mcp\Exceptions\MirroredParameterException;
use Hypervel\Mcp\Support\MirroredParameters;
use Hypervel\Mcp\Support\MirroredParameterType;
use Hypervel\Mcp\Support\SchemaPath;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Create an object schema with the given properties.
 */
function schemaWith(array $properties): array
{
    return ['type' => 'object', 'properties' => $properties];
}

class MirroredParametersTest extends TestCase
{
    public function testParsesAValidAnnotation(): void
    {
        $parameters = MirroredParameters::fromSchema(schemaWith([
            'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
            'query' => ['type' => 'string'],
        ]));

        $this->assertCount(1, $parameters->all());
        $parameter = $parameters->all()->first();
        $this->assertSame('Mcp-Param-Region', $parameter->header());
        $this->assertSame('Region', $parameter->name);
        $this->assertSame(MirroredParameterType::String, $parameter->type);
        $this->assertSame(['region'], $parameter->path->segments);
    }

    public function testParsesANestedAnnotation(): void
    {
        $parameters = MirroredParameters::fromSchema(schemaWith([
            'target' => ['type' => 'object', 'properties' => [
                'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
            ]],
        ]));

        $this->assertSame('/target/region', (string) $parameters->all()->first()->path);
        $this->assertSame(['Mcp-Param-Region' => 'us-west1'], $parameters->headers(['target' => ['region' => 'us-west1']]));
        $this->assertSame(['Mcp-Param-Region' => 'us-west1'], $parameters->headers(['target' => (object) ['region' => 'us-west1']]));
    }

    #[DataProvider('invalidAnnotations')]
    public function testRejectsInvalidAnnotations(array $properties, string $reason): void
    {
        $this->expectException(MirroredParameterException::class);
        $this->expectExceptionMessage($reason);
        MirroredParameters::fromSchema(schemaWith($properties));
    }

    /**
     * Provide invalid header annotations and their errors.
     */
    public static function invalidAnnotations(): array
    {
        return [
            'empty' => [['a' => ['type' => 'string', 'x-mcp-header' => '']], 'not a valid header name token'],
            'space' => [['a' => ['type' => 'string', 'x-mcp-header' => 'My Header']], 'not a valid header name token'],
            'newline' => [['a' => ['type' => 'string', 'x-mcp-header' => "Bad\nName"]], 'not a valid header name token'],
            'not a string' => [['a' => ['type' => 'string', 'x-mcp-header' => 12]], 'not a valid header name token'],
            'number type' => [['a' => ['type' => 'number', 'x-mcp-header' => 'A']], 'must sit on a string, integer, or boolean'],
            'array type' => [['a' => ['type' => 'array', 'x-mcp-header' => 'A']], 'must sit on a string, integer, or boolean'],
            'duplicate' => [[
                'a' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                'b' => ['type' => 'string', 'x-mcp-header' => 'region'],
            ], 'is used more than once'],
        ];
    }

    #[DataProvider('unreachableAnnotations')]
    public function testRejectsAnnotationsThatAreNotStaticallyReachable(array $schema): void
    {
        $this->expectException(MirroredParameterException::class);
        $this->expectExceptionMessage('statically reachable');
        MirroredParameters::fromSchema($schema);
    }

    /**
     * Provide schema locations without a fixed argument path.
     */
    public static function unreachableAnnotations(): array
    {
        return [
            'items' => [schemaWith([
                'rows' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']],
                ]],
            ])],
            'oneOf' => [schemaWith([
                'target' => ['oneOf' => [
                    ['type' => 'object', 'properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]],
                ]],
            ])],
            'root' => [['type' => 'object', 'x-mcp-header' => 'Region', 'properties' => []]],
        ];
    }

    public function testAllowsAPropertyLiterallyNamedLikeTheAnnotation(): void
    {
        $parameters = MirroredParameters::fromSchema(schemaWith([
            'x-mcp-header' => ['type' => 'string'],
        ]));

        $this->assertCount(0, $parameters);
    }

    public function testEncodesEveryMirroredType(): void
    {
        $parameters = MirroredParameters::fromSchema(schemaWith([
            'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
            'limit' => ['type' => 'integer', 'x-mcp-header' => 'Limit'],
            'dry' => ['type' => 'boolean', 'x-mcp-header' => 'Dry'],
            'label' => ['type' => 'string', 'x-mcp-header' => 'Label'],
            'absent' => ['type' => 'string', 'x-mcp-header' => 'Absent'],
            'null' => ['type' => 'string', 'x-mcp-header' => 'Null'],
        ]));

        $headers = $parameters->headers([
            'region' => 'us-west1',
            'limit' => 42,
            'dry' => false,
            'label' => 'Hello, 世界',
            'null' => null,
        ]);
        $this->assertSame([
            'Mcp-Param-Region' => 'us-west1',
            'Mcp-Param-Limit' => '42',
            'Mcp-Param-Dry' => 'false',
            'Mcp-Param-Label' => '=?base64?SGVsbG8sIOS4lueVjA==?=',
        ], $headers);
        $this->assertSame(['Mcp-Param-Region', 'Mcp-Param-Limit', 'Mcp-Param-Dry', 'Mcp-Param-Label'], array_keys($headers));
        $this->assertArrayNotHasKey('Mcp-Param-Absent', $headers);
        $this->assertArrayNotHasKey('Mcp-Param-Null', $headers);
        foreach ($headers as $value) {
            $this->assertIsString($value);
        }
    }

    public function testRejectsAnIntegerBeyondTheSafeRange(): void
    {
        $parameters = MirroredParameters::fromSchema(schemaWith([
            'limit' => ['type' => 'integer', 'x-mcp-header' => 'Limit'],
        ]));

        $this->expectException(MirroredParameterException::class);
        $this->expectExceptionMessage('cannot be mirrored');
        $parameters->headers(['limit' => 9007199254740992]);
    }

    public function testMirrorsAPropertyWhoseNameContainsADot(): void
    {
        $parameters = MirroredParameters::fromSchema(schemaWith([
            'a.b' => ['type' => 'string', 'x-mcp-header' => 'Dotted'],
            'a' => ['type' => 'object', 'properties' => [
                'b' => ['type' => 'string', 'x-mcp-header' => 'Nested'],
            ]],
        ]));

        $this->assertSame([
            'Mcp-Param-Dotted' => 'right',
            'Mcp-Param-Nested' => 'nested',
        ], $parameters->headers(['a.b' => 'right', 'a' => ['b' => 'nested']]));
    }

    public function testRendersPathsAsJsonPointers(): void
    {
        $this->assertSame('/a.b/c', (string) SchemaPath::root()->child('a.b')->child('c'));
    }
}
