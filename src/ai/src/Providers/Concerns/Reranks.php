<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Events\Reranked;
use Hypervel\Ai\Events\Reranking;
use Hypervel\Ai\Prompts\RerankingPrompt;
use Hypervel\Ai\Responses\RerankingResponse;
use Hypervel\Support\Str;

trait Reranks
{
    /**
     * Rerank the given documents based on their relevance to the query.
     *
     * @param array<int, string> $documents
     * @param array<string, mixed> $providerOptions
     */
    public function rerank(array $documents, string $query, ?int $limit = null, ?string $model = null, int $timeout = 30, array $providerOptions = []): RerankingResponse
    {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultRerankingModel();

        $prompt = new RerankingPrompt($documents, $query, $limit, $this, $model, $timeout, $providerOptions);

        if (Ai::rerankingIsFaked()) {
            Ai::recordReranking($prompt);
        }

        if ($this->events->hasListeners(Reranking::class)) {
            $this->events->dispatch(new Reranking(
                $invocationId,
                $this,
                $model,
                $prompt,
            ));
        }

        return tap($this->rerankingGateway()->rerank(
            $this,
            $model,
            $documents,
            $query,
            $limit,
            $timeout,
            $providerOptions,
        ), function (RerankingResponse $response) use ($invocationId, $model, $prompt): void {
            if ($this->events->hasListeners(Reranked::class)) {
                $this->events->dispatch(new Reranked(
                    $invocationId,
                    $this,
                    $model,
                    $prompt,
                    $response,
                ));
            }
        });
    }
}
