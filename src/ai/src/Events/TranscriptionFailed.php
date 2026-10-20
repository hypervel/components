<?php

declare(strict_types=1);

namespace Hypervel\Ai\Events;

use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Prompts\TranscriptionPrompt;
use Throwable;

/**
 * Dispatched once per failed attempt, including attempts failover recovers from.
 */
class TranscriptionFailed
{
    /**
     * Create an event for a failed transcription attempt.
     */
    public function __construct(
        public string $invocationId,
        public Provider $provider,
        public string $model,
        public TranscriptionPrompt $prompt,
        public Throwable $exception,
    ) {
    }
}
