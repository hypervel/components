<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Closure;
use Hypervel\Ai\Contracts\Gateway\RerankingGateway;
use Hypervel\Ai\Contracts\Providers\RerankingProvider;
use Hypervel\Ai\Prompts\RerankingPrompt;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\RankedDocument;
use Hypervel\Ai\Responses\Data\RerankingUsage;
use Hypervel\Ai\Responses\RerankingResponse;
use Hypervel\Support\Arr;
use RuntimeException;

class FakeRerankingGateway implements RerankingGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayRerankings = false;

    /**
     * Create a reranking gateway with fake responses.
     */
    public function __construct(
        protected Closure|array $responses = [],
    ) {
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
        $prompt = new RerankingPrompt($documents, $query, $limit, $provider, $model, $timeout, $providerOptions);

        return $this->nextResponse($provider, $model, $prompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(
        RerankingProvider $provider,
        string $model,
        RerankingPrompt $prompt
    ): RerankingResponse {
        // Reserve the entry first; callbacks may yield or throw.
        $index = $this->currentResponseIndex++;

        $response = is_array($this->responses)
            ? ($this->responses[$index] ?? null)
            : call_user_func($this->responses, $prompt);

        return $this->marshalResponse(
            $response,
            $provider,
            $model,
            $prompt
        );
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        RerankingProvider $provider,
        string $model,
        RerankingPrompt $prompt
    ): RerankingResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayRerankings) {
                throw new RuntimeException('Attempted reranking without a fake response.');
            }

            $response = $this->generateFakeRanking($prompt->documents, $prompt->limit);
        }

        if (is_array($response) && ($response === [] || ($response[0] ?? null) instanceof RankedDocument)) {
            return new RerankingResponse(
                $response,
                new RerankingUsage,
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Generate a fake ranking for the given documents.
     *
     * @param array<int, string> $documents
     * @return array<int, RankedDocument>
     */
    protected function generateFakeRanking(array $documents, ?int $limit = null): array
    {
        $indices = Arr::shuffle(array_keys($documents));

        $indices = array_slice($indices, 0, $limit ?? count($documents));

        $results = [];

        foreach ($indices as $position => $index) {
            $results[] = new RankedDocument(
                index: $index,
                document: $documents[$index],
                score: 1.0 - ($position * (1.0 / count($indices))),
            );
        }

        return $results;
    }

    /**
     * Indicate that an exception should be thrown if any reranking is not faked.
     *
     * Tests only. This setting affects the fake gateway shared by requests in the worker.
     */
    public function preventStrayRerankings(bool $prevent = true): self
    {
        $this->preventStrayRerankings = $prevent;

        return $this;
    }
}
