<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Prompts\EmbeddingsPrompt;
use Hypervel\Ai\Providers\Provider;

class GeneratingEmbeddings
{
    /**
     * Create an event for an embeddings generation request.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public EmbeddingsPrompt $prompt,
    ) {
    }
}
