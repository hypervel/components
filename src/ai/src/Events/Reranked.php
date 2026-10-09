<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Prompts\RerankingPrompt;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\RerankingResponse;

class Reranked
{
    /**
     * Create an event for reranked documents.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public RerankingPrompt $prompt,
        public RerankingResponse $response,
    ) {
    }
}
