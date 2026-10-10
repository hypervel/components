<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Bedrock;

use Aws\Result;
use Hypervel\Ai\Contracts\Gateway\RerankingGateway;
use Hypervel\Ai\Contracts\Providers\RerankingProvider;
use Hypervel\Ai\Gateway\Bedrock\Concerns\CreatesBedrockClient;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Ai\Responses\Data\RerankingUsage;
use Hypervel\Ai\Responses\RerankingResponse;
use Hypervel\Support\Collection;
use Throwable;

class BedrockRerankingGateway implements RerankingGateway
{
    use CreatesBedrockClient;
    use HandlesFailoverErrors;

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
        array $providerOptions = []
    ): RerankingResponse {
        /** @var Provider&RerankingProvider $provider */
        $client = $this->createBedrockClient($provider, $timeout);

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn (): Result => $client->invokeModel([
                    'modelId' => $model,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode(array_merge($providerOptions, array_filter([
                        'query' => $query,
                        'documents' => array_values($documents),
                        'top_n' => $limit,
                        'api_version' => str_starts_with($model, 'cohere.') ? 2 : null,
                    ], fn (mixed $value): bool => $value !== null))),
                ]),
            );

            $data = json_decode((string) $response->get('body')->getContents(), true);
        } catch (Throwable $throwable) {
            throw BedrockException::toAiException($throwable, $provider->name(), $model);
        }

        $results = (new Collection($data['results'] ?? []))->map(fn (array $result): RankedDocument => new RankedDocument(
            index: $result['index'],
            document: $documents[$result['index']],
            score: $result['relevance_score'],
        ))->all();

        return new RerankingResponse(
            $results,
            new RerankingUsage,
            new Meta($provider->name(), $model),
        );
    }
}
