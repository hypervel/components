<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Groq\Concerns;

use Hypervel\Ai\Attributes\Strict;
use Hypervel\Ai\Gateway\Concerns\ComposesSchemaInstructions;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\ObjectSchema;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\ToolChoice;
use Hypervel\Support\Arr;

trait BuildsTextRequests
{
    use ComposesSchemaInstructions;

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
     * Build the request body for the Chat Completions API.
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
        $hasTools = false;

        $body = ['model' => $model];

        if (filled($tools)) {
            $mappedTools = $this->mapTools($tools, $provider);

            if (filled($mappedTools)) {
                $body['tool_choice'] = $options?->toolChoice instanceof ToolChoice
                    ? $this->mapToolChoice($options->toolChoice)
                    : 'auto';
                $body['tools'] = $mappedTools;
                $hasTools = true;
            }
        }

        $inlineSchema = $hasTools && filled($schema);

        $body['messages'] = $this->mapMessagesToChat(
            $messages,
            $inlineSchema ? $this->composeInstructions($instructions, $schema) : $instructions,
        );

        if (filled($schema) && ! $inlineSchema) {
            $body['response_format'] = $this->buildResponseFormat($schema, Strict::isAppliedTo($options?->agent));
        }

        if ($options?->maxTokens !== null) {
            $body['max_completion_tokens'] = $options->maxTokens;
        }

        $body = array_merge($body, Arr::whereNotNull([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
        ]));

        $providerOptions = $options?->providerOptions($provider->driver());

        if (filled($providerOptions)) {
            return array_merge($body, $providerOptions);
        }

        return $body;
    }

    /**
     * Build the response format options for structured output.
     */
    protected function buildResponseFormat(array $schema, bool $strict): array
    {
        $objectSchema = new ObjectSchema($schema, strict: $strict);

        $schemaArray = $objectSchema->toSchema();

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $schemaArray['name'] ?? 'schema_definition',
                'schema' => Arr::except($schemaArray, ['name']),
                'strict' => $strict,
            ],
        ];
    }
}
