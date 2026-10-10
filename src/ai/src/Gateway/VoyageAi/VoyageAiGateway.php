<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\VoyageAi;

use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\RerankingGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\RerankingProvider;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\VoyageAi\Concerns\CreatesVoyageAiClient;
use Hypervel\Ai\Gateway\VoyageAi\Concerns\MapsEmbeddingInputs;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Ai\Responses\Data\RerankingUsage;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Responses\RerankingResponse;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;

class VoyageAiGateway implements EmbeddingGateway, RerankingGateway
{
    use CreatesVoyageAiClient;
    use MapsEmbeddingInputs;
    use HandlesFailoverErrors;

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
        if ($this->usesMultimodalEmbeddingEndpoint($model, $inputs)) {
            return $this->generateMultimodalEmbeddings($provider, $model, $inputs, $dimensions, $timeout, $providerOptions);
        }

        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('/embeddings', array_merge($providerOptions, [
                'model' => $model,
                'input' => $inputs,
                'output_dimension' => $dimensions,
            ])),
        )->json();

        return new EmbeddingsResponse(
            (new Collection($data['data']))->pluck('embedding')->all(),
            new Usage($data['usage']['total_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Rerank the given documents based on their relevance to the query.
     *
     * @param array<int, string> $documents
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
        $data = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('/rerank', array_merge($providerOptions, Arr::whereNotNull([
                'model' => $model,
                'query' => $query,
                'documents' => $documents,
                'top_k' => $limit,
            ]))),
        )->json();

        return new RerankingResponse(
            collect($data['data'])->map(fn (array $result): RankedDocument => new RankedDocument(
                index: $result['index'],
                document: $documents[$result['index']],
                score: $result['relevance_score'],
            ))->all(),
            new RerankingUsage($data['usage']['total_tokens'] ?? 0),
            new Meta($provider->name(), $model),
        );
    }
}
