<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Prompts\ClassificationPrompt;
use Hypervel\Ai\Providers\Provider;

class Classifying
{
    /**
     * Create an event for a classification request.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public ClassificationPrompt $prompt,
    ) {
    }
}
