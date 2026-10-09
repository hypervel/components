<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Prompts\EmbeddingsPrompt;
use Hypervel\Ai\Responses\EmbeddingsResponse;

class EmbeddingsGenerated
{
    /**
     * Create an event for generated embeddings.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public EmbeddingsPrompt $prompt,
        public EmbeddingsResponse $response,
    ) {
    }
}
