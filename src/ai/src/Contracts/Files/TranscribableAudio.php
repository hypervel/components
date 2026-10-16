<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Files;

use Hypervel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Stringable;

interface TranscribableAudio extends HasContent, HasMimeType, Stringable
{
    /**
     * Generate a transcription of the given audio.
     */
    public function transcription(): PendingTranscriptionGeneration;
}
