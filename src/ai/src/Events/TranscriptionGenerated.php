<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Prompts\TranscriptionPrompt;
use Hypervel\Ai\Responses\TranscriptionResponse;

class TranscriptionGenerated
{
    /**
     * Create an event for generated transcription.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public TranscriptionPrompt $prompt,
        public TranscriptionResponse $response,
    ) {
    }
}
