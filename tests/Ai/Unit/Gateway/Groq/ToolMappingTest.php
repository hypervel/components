<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Groq;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionTools;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Tests\Ai\TestCase;

class ToolMappingTest extends TestCase
{
    public function testToolParametersAreNotWrappedInSchemaDefinition(): void
    {
        $mapper = new class {
            use MapsChatCompletionTools;

            /**
             * Map the tool definitions.
             */
            public function map(array $tools): array
            {
                return array_map($this->mapTool(...), $tools);
            }
        };

        $tool = new class implements Tool {
            /**
             * Get the tool's description.
             */
            public function description(): string
            {
                return 'Creates a new lead';
            }

            /**
             * Execute the tool.
             */
            public function handle(Request $request): string
            {
                return 'done';
            }

            /**
             * Get the tool's schema.
             */
            public function schema(JsonSchema $schema): array
            {
                return [
                    'name' => $schema->string()->required()->description('full customer name'),
                    'email' => $schema->string()->nullable(),
                    'phone_number' => $schema->string()->required()->description("customer's phone number"),
                ];
            }
        };

        $mapped = $mapper->map([$tool]);

        $parameters = $mapped[0]['function']['parameters'];

        $this->assertArrayNotHasKey('schema_definition', $parameters['properties'] ?? []);
        $this->assertNotContains('schema_definition', $parameters['required'] ?? []);
        $this->assertArrayHasKey('name', $parameters['properties']);
        $this->assertArrayHasKey('email', $parameters['properties']);
        $this->assertArrayHasKey('phone_number', $parameters['properties']);
        $this->assertContains('name', $parameters['required']);
        $this->assertContains('phone_number', $parameters['required']);
        $this->assertSame('object', $parameters['type']);
        $this->assertFalse($parameters['additionalProperties']);
    }

    public function testToolWithEmptySchemaIncludesParameters(): void
    {
        $mapper = new class {
            use MapsChatCompletionTools;

            /**
             * Map the tool definitions.
             */
            public function map(array $tools): array
            {
                return array_map($this->mapTool(...), $tools);
            }
        };

        $tool = new class implements Tool {
            /**
             * Get the tool's description.
             */
            public function description(): string
            {
                return 'A tool with no parameters';
            }

            /**
             * Execute the tool.
             */
            public function handle(Request $request): string
            {
                return 'done';
            }

            /**
             * Get the tool's schema.
             */
            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $mapped = $mapper->map([$tool]);

        $function = $mapped[0]['function'];

        $this->assertArrayHasKey('parameters', $function);
        $this->assertSame('object', $function['parameters']['type']);
        $this->assertSame([], $function['parameters']['required']);
        $this->assertFalse($function['parameters']['additionalProperties']);
    }
}
