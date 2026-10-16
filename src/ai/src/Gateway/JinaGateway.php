<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\RerankingGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\RerankingProvider;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Ai\Responses\Data\RerankingUsage;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Responses\RerankingResponse;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;

class JinaGateway implements EmbeddingGateway, RerankingGateway
{
    use Concerns\CreatesClient;
    use HandlesFailoverErrors;

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
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('/embeddings', array_merge(
                ['task' => 'retrieval.passage'],
                $providerOptions,
                [
                    'model' => $model,
                    'input' => array_map(fn (string $text): array => ['text' => $text], $inputs),
                    'dimensions' => $dimensions,
                ],
            )),
        );

        $data = $response->json();

        $embeddings = (new Collection($data['data']))->pluck('embedding')->all();

        return new EmbeddingsResponse(
            $embeddings,
            new Usage($data['usage']['total_tokens'] ?? 0),
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
            new RerankingUsage($data['usage']['total_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Get an HTTP client for the Jina API.
     */
    protected function client(EmbeddingProvider|RerankingProvider $provider, int $timeout = 30): PendingRequest
    {
        return $this->createClient(
            $this->baseUrl($provider),
            [
                'Authorization' => 'Bearer ' . $provider->providerCredentials()['key'],
                'Content-Type' => 'application/json',
            ],
            $provider->additionalConfiguration()['headers'] ?? [],
            $timeout,
        )->connection($this->httpConnection($provider));
    }

    /**
     * Get the base URL for the Jina API.
     */
    protected function baseUrl(EmbeddingProvider|RerankingProvider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.jina.ai/v1', '/');
    }
}
