<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Prompts\EmbeddingsPrompt;
use Throwable;

class EmbeddingsFailed
{
    /**
     * Create an event for a failed embeddings generation attempt.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public EmbeddingsPrompt $prompt,
        public Throwable $exception,
    ) {
    }
}
