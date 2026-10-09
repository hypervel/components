<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Prompts\ClassificationPrompt;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\ClassificationResponse;

class Classified
{
    /**
     * Create an event for completed classification.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public ClassificationPrompt $prompt,
        public ClassificationResponse $response,
    ) {
    }
}
