<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Prompts\TranscriptionPrompt;
use Hypervel\Ai\Providers\Provider;

class GeneratingTranscription
{
    /**
     * Create an event for a transcription request.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public TranscriptionPrompt $prompt,
    ) {
    }
}
