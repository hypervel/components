<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Prompts\AudioPrompt;
use Hypervel\Ai\Providers\Provider;

class GeneratingAudio
{
    /**
     * Create an event for an audio generation request.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public AudioPrompt $prompt,
    ) {
    }
}
