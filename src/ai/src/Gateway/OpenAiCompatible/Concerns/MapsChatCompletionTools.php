<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenAiCompatible\Concerns;

use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\ObjectSchema;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Providers\Tools\ProviderTool;
use Hypervel\Ai\ToolChoice;
use Hypervel\Ai\Tools\ToolNameResolver;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\Support\Str;
use RuntimeException;

trait MapsChatCompletionTools
{
    /**
     * Map the given tools to Chat Completions function definitions.
     */
    protected function mapTools(array $tools, Provider $provider): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof ProviderTool) {
                $mapped[] = $this->mapProviderTool($tool, $provider);
            } elseif ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Map a provider tool to a Chat Completions tool definition.
     */
    protected function mapProviderTool(ProviderTool $tool, Provider $provider): array
    {
        throw new RuntimeException(Str::of(class_basename($this))->before('Gateway') . ' does not support [' . class_basename($tool) . '] provider tools.');
    }

    /**
     * Map a regular tool to a Chat Completions function definition.
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
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Map a tool choice to the Chat Completions tool_choice shape.
     *
     * @return array<string, mixed>|string
     */
    protected function mapToolChoice(ToolChoice $choice): string|array
    {
        return match ($choice->mode) {
            ToolChoice::auto, ToolChoice::none, ToolChoice::required => $choice->mode,
            ToolChoice::tool => [
                'type' => 'function',
                'function' => [
                    'name' => $choice->toolName,
                ],
            ],
        };
    }
}
