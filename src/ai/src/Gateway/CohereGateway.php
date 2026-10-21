<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Generator;
use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\RerankingGateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\RerankingProvider;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Gateway\Cohere\Concerns\BuildsTextRequests;
use Hypervel\Ai\Gateway\Cohere\Concerns\HandlesTextStreaming;
use Hypervel\Ai\Gateway\Cohere\Concerns\ParsesEmbeddings;
use Hypervel\Ai\Gateway\Cohere\Concerns\ParsesTextResponses;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\MapsAttachments;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionMessages;
use Hypervel\Ai\Gateway\OpenAiCompatible\Concerns\MapsChatCompletionTools;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Ai\Responses\Data\RerankingUsage;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Responses\RerankingResponse;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;

class CohereGateway implements EmbeddingGateway, RerankingGateway, StepTextGateway
{
    use BuildsTextRequests;
    use Concerns\CreatesClient;
    use HandlesFailoverErrors;
    use HandlesTextStreaming;
    use MapsAttachments;
    use MapsChatCompletionMessages;
    use MapsChatCompletionTools;
    use ParsesEmbeddings;
    use ParsesServerSentEvents;
    use ParsesTextResponses;

    /**
     * Generate text for a single step in a conversation.
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
        $body = $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 60)->post('/chat', $body),
        );

        $data = $response->json();

        $this->validateTextResponse($data);

        return $this->parseTextResponse($data, $provider, $model, filled($schema))->withRawResponse($response);
    }

    /**
     * Stream text for a single step in a conversation.
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
        $body = $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $body['stream'] = true;

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 60)
                ->withOptions(['stream' => true])
                ->post('/chat', $body),
        );

        return yield from $this->processTextStream($invocationId, $provider, $model, $response->getBody());
    }

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param string[] $inputs
     * @param array<string, mixed> $providerOptions
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
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('/embed', array_merge(
                [
                    'input_type' => 'search_document',
                    'embedding_types' => ['float'],
                ],
                $providerOptions,
                [
                    'model' => $model,
                    'texts' => $inputs,
                    ...(! str_starts_with($model, 'embed-english-') && ! str_starts_with($model, 'embed-multilingual-')
                        ? ['output_dimension' => $dimensions]
                        : []),
                ],
            )),
        );

        $data = $response->json();

        return new EmbeddingsResponse(
            $this->parseCohereEmbeddings($data['embeddings'] ?? []),
            new Usage($data['meta']['billed_units']['input_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Rerank the given documents based on their relevance to the query.
     *
     * @param list<string> $documents
     * @param array<string, mixed> $providerOptions
     */
    public function rerank(
        RerankingProvider $provider,
        string $model,
        array $documents,
        string $query,
        ?int $limit = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): RerankingResponse {
        /** @var Provider&RerankingProvider $provider */
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('/rerank', array_merge($providerOptions, Arr::whereNotNull([
                'model' => $model,
                'query' => $query,
                'documents' => $documents,
                'top_n' => $limit,
            ]))),
        );

        $data = $response->json();

        $results = (new Collection($data['results']))->map(fn (array $result): RankedDocument => new RankedDocument(
            index: $result['index'],
            document: $documents[$result['index']],
            score: $result['relevance_score'],
        ))->all();

        return new RerankingResponse(
            $results,
            new RerankingUsage(
                inputTokens: $data['meta']['billed_units']['input_tokens'] ?? 0,
                searchUnits: $data['meta']['billed_units']['search_units'] ?? null,
            ),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get an HTTP client for the Cohere API.
     */
    protected function client(Provider $provider, int $timeout = 30): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        return $this->createClient(
            $config['url'] ?? 'https://api.cohere.com/v2',
            [
                'Authorization' => 'Bearer ' . $provider->providerCredentials()['key'],
                'Content-Type' => 'application/json',
            ],
            $config['headers'] ?? [],
            $timeout,
        )->connection($this->httpConnection($provider));
    }
}
