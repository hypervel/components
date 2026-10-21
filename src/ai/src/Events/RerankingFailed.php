<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Prompts\RerankingPrompt;
use Throwable;

/**
 * Dispatched once per failed attempt, including attempts failover recovers from.
 */
class RerankingFailed
{
    /**
     * Create an event for a failed reranking attempt.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public RerankingPrompt $prompt,
        public Throwable $exception,
    ) {
    }
}
