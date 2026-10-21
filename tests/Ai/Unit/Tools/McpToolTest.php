<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Tools;

use Hypervel\Ai\ObjectSchema;
use Hypervel\Ai\Tools\McpTool;
use Hypervel\Ai\Tools\Request;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\Tests\Ai\Fixtures\Mcp\FakeMcpClient;
use Hypervel\Tests\Ai\Fixtures\Mcp\FakeMcpTool;
use Hypervel\Tests\Ai\Fixtures\Mcp\FakeMcpToolResult;
use Hypervel\Tests\TestCase;
use stdClass;

class McpToolTest extends TestCase
{
    public function testItDetectsMcpClientToolPrimitives(): void
    {
        $this->assertTrue(McpTool::supports(mcpTool(new FakeMcpClient)));
        $this->assertFalse(McpTool::supports(new stdClass));
    }

    public function testItDoesNotOwnTheMcpClientLifecycle(): void
    {
        $client = new FakeMcpClient;

        new McpTool(mcpTool($client));

        $this->assertSame(0, $client->connects);
        $this->assertSame(0, $client->disconnects);
    }

    public function testItTranslatesMcpInputSchemasToHypervelToolSchemas(): void
    {
        $tool = new McpTool(mcpTool(new FakeMcpClient, inputSchema: [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query.',
                    'enum' => ['name', 'email'],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum result count.',
                    'minimum' => 1,
                    'maximum' => 10,
                ],
                'filters' => [
                    'type' => 'object',
                    'properties' => [
                        'active' => [
                            'type' => 'boolean',
                            'description' => 'Only active records.',
                        ],
                    ],
                    'required' => ['active'],
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['vip', 'trial'],
                    ],
                ],
                'email' => [
                    'type' => ['string', 'null'],
                    'format' => 'email',
                ],
            ],
            'required' => ['query', 'filters'],
        ]));

        $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

        $this->assertEquals([
            'required' => ['query', 'filters'],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query.',
                    'enum' => ['name', 'email'],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum result count.',
                    'minimum' => 1,
                    'maximum' => 10,
                ],
                'filters' => [
                    'type' => 'object',
                    'required' => ['active'],
                    'additionalProperties' => false,
                    'properties' => [
                        'active' => [
                            'type' => 'boolean',
                            'description' => 'Only active records.',
                        ],
                    ],
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => ['vip', 'trial'],
                    ],
                ],
                'email' => [
                    'type' => ['string', 'null'],
                    'format' => 'email',
                ],
            ],
        ], array_intersect_key($schema, array_flip(['required', 'properties'])));
    }

    public function testItResolvesRefAgainstDefsAndMergesSiblingKeys(): void
    {
        $tool = new McpTool(mcpTool(new FakeMcpClient, inputSchema: [
            'type' => 'object',
            'properties' => [
                'address' => [
                    '$ref' => '#/$defs/Address',
                    'description' => 'Shipping address.',
                ],
            ],
            'required' => ['address'],
            '$defs' => [
                'Address' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string'],
                    ],
                    'required' => ['city'],
                ],
            ],
        ]));

        $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

        $this->assertEquals([
            'type' => 'object',
            'description' => 'Shipping address.',
            'required' => ['city'],
            'properties' => [
                'city' => ['type' => 'string'],
            ],
        ], array_intersect_key($schema['properties']['address'], array_flip(['type', 'description', 'required', 'properties'])));
    }

    public function testItDropsAnUnresolvableRefInsteadOfFailingTheWholeTool(): void
    {
        $tool = new McpTool(mcpTool(new FakeMcpClient, inputSchema: [
            'type' => 'object',
            'properties' => [
                'address' => ['$ref' => '#/$defs/Missing', 'description' => 'Shipping address.'],
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ]));

        $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

        $this->assertArrayHasKey('address', $schema['properties']);
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertEquals([
            'type' => 'string',
            'description' => 'Shipping address.',
        ], $schema['properties']['address']);
        $this->assertSame(['type' => 'string'], $schema['properties']['name']);
    }

    public function testItDropsTheParameterSchemaWhenSharedRefExpansionExceedsTheNodeBudget(): void
    {
        $defs = ['a13' => ['type' => 'string']];

        for ($i = 0; $i < 13; ++$i) {
            $defs["a{$i}"] = [
                'type' => 'object',
                'properties' => [
                    'left' => ['$ref' => '#/$defs/a' . ($i + 1)],
                    'right' => ['$ref' => '#/$defs/a' . ($i + 1)],
                ],
            ];
        }

        $tool = new McpTool(mcpTool(new FakeMcpClient, inputSchema: ['$ref' => '#/$defs/a0', '$defs' => $defs]));

        $this->assertSame([], $tool->schema(new JsonSchemaTypeFactory));
    }

    public function testItKeepsThePropertiesOfANullableObjectRoot(): void
    {
        $tool = new McpTool(mcpTool(new FakeMcpClient, inputSchema: [
            'anyOf' => [
                [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                    'required' => ['query'],
                ],
                ['type' => 'null'],
            ],
        ]));

        $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

        $this->assertArrayHasKey('query', $schema['properties']);
        $this->assertSame(['type' => 'string'], $schema['properties']['query']);
    }

    public function testItMarksFieldsNullableWhenAnyOfOrOneOfListsNullAfterTheTypedBranch(): void
    {
        $tool = new McpTool(mcpTool(new FakeMcpClient, inputSchema: [
            'type' => 'object',
            'properties' => [
                'nickname' => [
                    'anyOf' => [
                        ['type' => 'string', 'minLength' => 1],
                        ['type' => 'null'],
                    ],
                ],
                'count' => [
                    'oneOf' => [
                        ['type' => 'integer', 'minimum' => 0],
                        ['type' => 'null'],
                    ],
                ],
            ],
        ]));

        $schema = (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toSchema();

        $this->assertEquals([
            'type' => ['string', 'null'],
            'minLength' => 1,
        ], $schema['properties']['nickname']);

        $this->assertEquals([
            'type' => ['integer', 'null'],
            'minimum' => 0,
        ], $schema['properties']['count']);
    }

    public function testItReturnsTextContentFromSuccessfulMcpCalls(): void
    {
        $client = new FakeMcpClient;
        $tool = new McpTool(mcpTool($client, name: 'search'));

        $client->results['search'] = new FakeMcpToolResult([
            ['type' => 'text', 'text' => 'Found results.'],
        ], false);

        $this->assertSame('Found results.', $tool->handle(new Request(['query' => 'hypervel'])));

        $this->assertSame(
            [
                ['name' => 'search', 'arguments' => ['query' => 'hypervel']],
            ],
            $client->toolCalls,
        );
    }

    public function testItPrefersStructuredContentFromSuccessfulMcpCalls(): void
    {
        $client = new FakeMcpClient;
        $tool = new McpTool(mcpTool($client, name: 'lookup'));

        $client->results['lookup'] = new FakeMcpToolResult([
            ['type' => 'text', 'text' => 'ignored'],
        ], false, [
            'id' => 1,
            'name' => 'Taylor',
        ]);

        $this->assertSame([
            'id' => 1,
            'name' => 'Taylor',
        ], json_decode($tool->handle(new Request(['id' => 1])), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testItDoesNotEscapeSlashesInStructuredContentJson(): void
    {
        $client = new FakeMcpClient;
        $tool = new McpTool(mcpTool($client, name: 'lookup'));

        $client->results['lookup'] = new FakeMcpToolResult([
            ['type' => 'text', 'text' => 'ignored'],
        ], false, [
            'url' => 'https://example.com/report',
        ]);

        $this->assertSame('{"url":"https://example.com/report"}', $tool->handle(new Request(['id' => 1])));
    }

    public function testItSurfacesApplicationLevelMcpErrorsAsToolOutput(): void
    {
        $client = new FakeMcpClient;
        $tool = new McpTool(mcpTool($client, name: 'lookup'));

        $client->results['lookup'] = new FakeMcpToolResult([
            ['type' => 'text', 'text' => 'Record not found.'],
        ], true);

        $this->assertSame('MCP tool error: Record not found.', $tool->handle(new Request(['id' => 1])));
    }

    public function testItFallsBackToTitleThenNameWhenDescriptionIsNull(): void
    {
        $withTitle = new McpTool(mcpTool(new FakeMcpClient, name: 'lookup', title: 'Lookup record', description: null));
        $withoutTitle = new McpTool(mcpTool(new FakeMcpClient, name: 'fallback_name', description: null));

        $this->assertSame('Lookup record', $withTitle->description());
        $this->assertSame('fallback_name', $withoutTitle->description());
    }
}

/**
 * Create an MCP tool primitive.
 *
 * @param array<string, mixed> $inputSchema
 */
function mcpTool(
    FakeMcpClient $client,
    string $name = 'tool',
    ?string $title = null,
    ?string $description = 'Tool description.',
    array $inputSchema = ['type' => 'object', 'properties' => []],
): FakeMcpTool {
    return new FakeMcpTool($client, $name, $title, $description, $inputSchema);
}
