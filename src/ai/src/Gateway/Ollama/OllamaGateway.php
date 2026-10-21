<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Ollama;

use Generator;
use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\Ollama\Concerns\BuildsTextRequests;
use Hypervel\Ai\Gateway\Ollama\Concerns\CreatesOllamaClient;
use Hypervel\Ai\Gateway\Ollama\Concerns\HandlesTextStreaming;
use Hypervel\Ai\Gateway\Ollama\Concerns\MapsAttachments;
use Hypervel\Ai\Gateway\Ollama\Concerns\MapsMessages;
use Hypervel\Ai\Gateway\Ollama\Concerns\MapsTools;
use Hypervel\Ai\Gateway\Ollama\Concerns\ParsesTextResponses;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Contracts\Events\Dispatcher;

class OllamaGateway implements EmbeddingGateway, StepTextGateway
{
    use BuildsTextRequests;
    use CreatesOllamaClient;
    use HandlesTextStreaming;
    use MapsAttachments;
    use MapsMessages;
    use MapsTools;
    use ParsesTextResponses;
    use HandlesFailoverErrors;

    /**
     * Create an Ollama gateway instance.
     */
    public function __construct(protected Dispatcher $events)
    {
    }

    /**
     * Generate text for a single Ollama Chat API step.
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
            fn () => $this->client($provider, $timeout)->post('api/chat', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, filled($schema))->withRawResponse($response);
    }

    /**
     * Stream text for a single Ollama Chat API step.
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

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)
                ->withOptions(['stream' => true])
                ->post('api/chat', $body),
        );

        return yield from $this->processTextStream($invocationId, $provider, $model, $response->getBody());
    }

    /**
     * Generate embeddings for the given inputs.
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        /** @var EmbeddingProvider&Provider $provider */
        $body = array_merge($providerOptions, array_filter([
            'model' => $model,
            'input' => $inputs,
            'dimensions' => $dimensions ?: null,
        ]));

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('api/embed', $body),
        );

        $data = $response->json();

        if (! $data || isset($data['error'])) {
            throw new AiException(sprintf(
                'Ollama Error: %s',
                $data['error'] ?? 'Unknown Ollama error.',
            ));
        }

        return new EmbeddingsResponse(
            $data['embeddings'] ?? [],
            new Usage($data['prompt_eval_count'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }
}
