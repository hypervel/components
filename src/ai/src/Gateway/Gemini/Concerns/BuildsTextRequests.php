<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Gemini\Concerns;

use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\ObjectSchema;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Ai\ToolChoice;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;

trait BuildsTextRequests
{
    /**
     * The request keys Gemini expects beside the generation config rather than within it.
     *
     * @var array<int, string>
     */
    private const array TOP_LEVEL_INTERACTION_KEYS = [
        'agent', 'agent_config', 'background', 'environment', 'labels',
        'previous_interaction_id', 'response_format', 'safety_settings',
        'service_tier', 'store', 'user_metadata', 'webhook_config',
    ];

    /**
     * Build the request body for the current text generation step.
     */
    protected function buildStepBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        return $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);
    }

    /**
     * Build the request body for the Gemini Interactions API.
     */
    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        $input = $this->mapMessagesToInput($messages);

        return $this->assembleRequestBody($model, $input, $instructions, $tools, $schema, $options, $provider);
    }

    /**
     * Assemble the Gemini request body from the given components.
     */
    private function assembleRequestBody(
        string $model,
        array $input,
        ?string $instructions,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        Provider $provider,
    ): array {
        $body = [
            'model' => $model,
            'input' => $input,
            // Conversation history is replayed from our own store, so Gemini need not retain it...
            'store' => false,
        ];

        if (filled($instructions)) {
            $body['system_instruction'] = $instructions;
        }

        if (filled($tools)) {
            $body['tools'] = $this->mapTools($tools, $provider);
        }

        if (filled($schema)) {
            $body['response_format'] = [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => $this->buildResponseSchema($schema),
            ];
        }

        $generationConfig = Arr::whereNotNull([
            'max_output_tokens' => $options?->maxTokens,
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
        ]);

        if ($options?->toolChoice instanceof ToolChoice) {
            $generationConfig['tool_choice'] = $this->toolChoiceConfig($options->toolChoice);
        }

        $providerOptions = $options?->providerOptions($provider->driver()) ?? [];

        foreach (['generation_config', 'generationConfig'] as $key) {
            if (is_array($providerOptions[$key] ?? null)) {
                $providerOptions = array_merge(
                    Arr::except($providerOptions, $key),
                    $providerOptions[$key],
                );
            }
        }

        foreach ($providerOptions as $key => $value) {
            if (in_array($snakeKey = Str::snake($key), self::TOP_LEVEL_INTERACTION_KEYS, true)) {
                $body[$snakeKey] = $value;
                unset($providerOptions[$key]);
            }
        }

        if (filled($providerOptions)) {
            $generationConfig = array_merge($generationConfig, $providerOptions);
        }

        if (filled($generationConfig)) {
            $body['generation_config'] = $generationConfig;
        }

        return $body;
    }

    /**
     * Build function result steps from tool results for the Gemini API.
     *
     * @param array<ToolResult> $toolResults
     */
    protected function buildFunctionResultSteps(array $toolResults): array
    {
        return array_values(array_map(fn (ToolResult $result): array => [
            'type' => 'function_result',
            'name' => $result->name,
            'call_id' => $result->id,
            'result' => [['type' => 'text', 'text' => $result->text()]],
        ], $toolResults));
    }

    /**
     * Build the response schema for structured output.
     */
    protected function buildResponseSchema(array $schema): array
    {
        return (new ObjectSchema($schema))->toSchema();
    }

    /**
     * Map a tool choice to the Gemini tool choice configuration.
     */
    protected function toolChoiceConfig(ToolChoice $choice): string|array
    {
        return match ($choice->mode) {
            ToolChoice::auto => 'auto',
            ToolChoice::none => 'none',
            ToolChoice::required => 'any',
            ToolChoice::tool => [
                'allowed_tools' => [
                    'mode' => 'any',
                    'tools' => [$choice->toolName],
                ],
            ],
        };
    }
}
