<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Prompts\RerankingPrompt;
use Hypervel\Ai\Providers\Provider;

class Reranking
{
    /**
     * Create an event for a reranking request.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public RerankingPrompt $prompt,
    ) {
    }
}
