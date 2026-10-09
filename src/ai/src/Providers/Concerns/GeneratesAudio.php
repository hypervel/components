<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Events\AudioGenerated;
use Hypervel\Ai\Events\GeneratingAudio;
use Hypervel\Ai\Prompts\AudioPrompt;
use Hypervel\Ai\Responses\AudioResponse;
use Hypervel\Support\Str;

trait GeneratesAudio
{
    /**
     * Generate audio from the given text.
     *
     * @param array<string, mixed> $providerOptions
     */
    public function audio(
        string $text,
        string $voice = 'default-female',
        ?string $instructions = null,
        ?string $model = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): AudioResponse {
        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultAudioModel();

        $prompt = new AudioPrompt($text, $voice, $instructions, $this, $model, $timeout, $providerOptions);

        if (Ai::audioIsFaked()) {
            Ai::recordAudioGeneration($prompt);
        }

        if ($this->events->hasListeners(GeneratingAudio::class)) {
            $this->events->dispatch(new GeneratingAudio(
                $invocationId,
                $this,
                $model,
                $prompt,
            ));
        }

        return tap($this->audioGateway()->generateAudio(
            $this,
            $model,
            $prompt->text,
            $prompt->voice,
            $prompt->instructions,
            $timeout,
            $prompt->providerOptions,
        ), function (AudioResponse $response) use ($invocationId, $model, $prompt): void {
            if ($this->events->hasListeners(AudioGenerated::class)) {
                $this->events->dispatch(new AudioGenerated(
                    $invocationId,
                    $this,
                    $model,
                    $prompt,
                    $response,
                ));
            }
        });
    }
}
