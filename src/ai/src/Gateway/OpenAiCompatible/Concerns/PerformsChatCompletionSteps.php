<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenAiCompatible\Concerns;

use Generator;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Providers\Provider;

trait PerformsChatCompletionSteps
{
    /**
     * Generate text for a single Chat Completions step.
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        /** @var Provider&TextProvider $provider */
        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->withBody($this->encodeBody($body))->post('chat/completions'),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, filled($schema))->withRawResponse($response);
    }

    /**
     * Stream text for a single Chat Completions step.
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        /** @var Provider&TextProvider $provider */
        $body = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        $body['stream'] = true;

        if (filled($streamOptions = $this->streamOptions($provider))) {
            $body['stream_options'] = $streamOptions;
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->withBody($this->encodeBody($body))
                ->post('chat/completions'),
        );

        return yield from $this->processTextStream($invocationId, $provider, $model, $response->getBody());
    }

    /**
     * Encode the request body without escaping slashes, which OpenRouter misreads in large data URLs.
     */
    protected function encodeBody(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Get the stream options sent with a streaming Chat Completions request.
     */
    protected function streamOptions(Provider $provider): ?array
    {
        return ['include_usage' => true];
    }
}
