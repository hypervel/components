<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Xai;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Gateway\Xai\Concerns\MapsTools;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Tests\TestCase;

class ToolMappingTest extends TestCase
{
    public function testToolParametersAreNotWrappedInSchemaDefinition(): void
    {
        $mapper = new class {
            use MapsTools;

            /**
             * Map tools for the given provider.
             */
            public function map(array $tools, Provider $provider): array
            {
                return $this->mapTools($tools, $provider);
            }
        };

        $tool = new class implements Tool {
            /**
             * Describe the tool.
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
             * Define the tool parameters.
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

        $provider = $this->createStub(Provider::class);

        $mapped = $mapper->map([$tool], $provider);

        $parameters = $mapped[0]['parameters'];

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
            use MapsTools;

            /**
             * Map tools for the given provider.
             */
            public function map(array $tools, Provider $provider): array
            {
                return $this->mapTools($tools, $provider);
            }
        };

        $tool = new class implements Tool {
            /**
             * Describe the tool.
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
             * Define the tool parameters.
             */
            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $provider = $this->createStub(Provider::class);

        $mapped = $mapper->map([$tool], $provider);

        $this->assertArrayHasKey('parameters', $mapped[0]);
        $this->assertSame('object', $mapped[0]['parameters']['type']);
        $this->assertEmpty($mapped[0]['parameters']['required']);
        $this->assertFalse($mapped[0]['parameters']['additionalProperties']);
    }
}
