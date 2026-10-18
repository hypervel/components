<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Ollama\Concerns;

use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Gateway\Concerns\ComposesSchemaInstructions;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\ObjectSchema;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\ToolChoice;
use Hypervel\Support\Arr;
use InvalidArgumentException;

trait BuildsTextRequests
{
    use ComposesSchemaInstructions;

    /**
     * Build the request body for the current text generation step.
     *
     * @param Provider&TextProvider $provider
     */
    protected function buildStepBody(
        TextProvider $provider,
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
     * Build the request body for the Ollama Chat API.
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
        return $this->buildChatRequestBody(
            $provider,
            $model,
            $this->mapMessagesToChat($messages, $this->composeInstructions($instructions, $schema)),
            $tools,
            $schema,
            $options,
        );
    }

    /**
     * Build a request body from pre-mapped chat messages.
     */
    protected function buildChatRequestBody(
        Provider $provider,
        string $model,
        array $chatMessages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        bool $stream = false,
    ): array {
        $body = [
            'model' => $model,
            'messages' => $chatMessages,
            'stream' => $stream,
        ];

        if (filled($tools)) {
            $mappedTools = $this->mapTools($tools);

            if (filled($mappedTools)) {
                if ($options?->toolChoice !== null && $options->toolChoice->mode !== ToolChoice::auto) {
                    throw new InvalidArgumentException('Ollama does not support tool choice other than auto.');
                }

                $body['tools'] = $mappedTools;
            }
        }

        if (filled($schema)) {
            $body['format'] = $this->buildResponseFormat($schema);
        }

        $ollamaOptions = Arr::whereNotNull([
            'temperature' => $options?->temperature,
            'top_p' => $options?->topP,
            'num_predict' => $options?->maxTokens,
        ]);

        $providerOptions = $options?->providerOptions($provider->driver()) ?? [];

        // Hoist keys that belong at the top level, as everything else is passed in options.
        $topLevelKeys = ['format', 'keep_alive', 'think', 'logprobs', 'top_logprobs'];

        foreach ($topLevelKeys as $key) {
            if (array_key_exists($key, $providerOptions)) {
                // A schema-driven format already on the body wins.
                $body[$key] ??= $providerOptions[$key];
                unset($providerOptions[$key]);
            }
        }

        $mergedOptions = array_merge($ollamaOptions, $providerOptions);

        if (filled($mergedOptions)) {
            $body['options'] = $mergedOptions;
        }

        return $body;
    }

    /**
     * Build the response format schema for structured output.
     */
    protected function buildResponseFormat(array $schema): array
    {
        return (new ObjectSchema($schema))->toSchema();
    }
}
