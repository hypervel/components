<?php

declare(strict_types=1);

namespace Hypervel\Ai\Prompts;

use Hypervel\Ai\Contracts\Files\TranscribableAudio;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;

class TranscriptionPrompt
{
    /**
     * Create a transcription prompt.
     *
     * @param array<string, mixed> $providerOptions
     */
    public function __construct(
        public readonly TranscribableAudio $audio,
        public readonly ?string $language,
        public readonly bool $diarize,
        public readonly TranscriptionProvider $provider,
        public readonly string $model,
        public readonly ?int $timeout = null,
        public readonly array $providerOptions = [],
    ) {
    }

    /**
     * Determine if the transcription is diarized.
     */
    public function isDiarized(): bool
    {
        return $this->diarize;
    }
}
