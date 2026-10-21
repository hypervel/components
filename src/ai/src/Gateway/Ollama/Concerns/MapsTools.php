<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Ollama\Concerns;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\ObjectSchema;
use Hypervel\Ai\Providers\Tools\ProviderTool;
use Hypervel\Ai\Tools\ToolNameResolver;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use RuntimeException;

trait MapsTools
{
    /**
     * Map the given tools to Ollama function definitions.
     */
    protected function mapTools(array $tools): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                throw new RuntimeException('Ollama does not support [' . class_basename($tool) . '] provider tools.');
            }

            if ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Map a regular tool to an Ollama function definition.
     */
    protected function mapTool(Tool $tool): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $schemaArray = filled($schema)
            ? (new ObjectSchema($schema))->toSchema()
            : [];

        return [
            'type' => 'function',
            'function' => [
                'name' => ToolNameResolver::resolve($tool),
                'description' => (string) $tool->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $schemaArray['properties'] ?? (object) [],
                    'required' => $schemaArray['required'] ?? [],
                ],
            ],
        ];
    }
}
