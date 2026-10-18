<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Prompts\AudioPrompt;
use Hypervel\Ai\Responses\AudioResponse;

class AudioGenerated
{
    /**
     * Create an event for generated audio.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public AudioPrompt $prompt,
        public AudioResponse $response,
    ) {
    }
}
