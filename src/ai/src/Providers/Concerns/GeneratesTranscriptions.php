<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Contracts\Files\TranscribableAudio;
use Hypervel\Ai\Events\GeneratingTranscription;
use Hypervel\Ai\Events\TranscriptionGenerated;
use Hypervel\Ai\Prompts\TranscriptionPrompt;
use Hypervel\Ai\Responses\TranscriptionResponse;
use Hypervel\Support\Str;

trait GeneratesTranscriptions
{
    /**
     * Transcribe the given audio to text.
     *
     * @param array<string, mixed> $providerOptions
     */
    public function transcribe(
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        ?string $model = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): TranscriptionResponse {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultTranscriptionModel();

        $prompt = new TranscriptionPrompt($audio, $language, $diarize, $this, $model, $timeout, $providerOptions);

        if (Ai::transcriptionsAreFaked()) {
            Ai::recordTranscriptionGeneration($prompt);
        }

        if ($this->events->hasListeners(GeneratingTranscription::class)) {
            $this->events->dispatch(new GeneratingTranscription(
                $invocationId,
                $this,
                $model,
                $prompt,
            ));
        }

        return tap($this->transcriptionGateway()->generateTranscription(
            $this,
            $model,
            $prompt->audio,
            $prompt->language,
            $prompt->diarize,
            $prompt->timeout ?? 30,
            $prompt->providerOptions
        ), function (TranscriptionResponse $response) use ($invocationId, $model, $prompt): void {
            if ($this->events->hasListeners(TranscriptionGenerated::class)) {
                $this->events->dispatch(new TranscriptionGenerated(
                    $invocationId,
                    $this,
                    $model,
                    $prompt,
                    $response
                ));
            }
        });
    }
}
