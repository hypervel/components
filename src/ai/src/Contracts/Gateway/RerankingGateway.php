<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Gateway;

use Hypervel\Ai\Contracts\Providers\RerankingProvider;
use Hypervel\Ai\Responses\RerankingResponse;

interface RerankingGateway
{
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
    ): RerankingResponse;
}
