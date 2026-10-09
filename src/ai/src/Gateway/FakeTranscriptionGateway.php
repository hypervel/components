<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Closure;
use Hypervel\Ai\Contracts\Files\TranscribableAudio;
use Hypervel\Ai\Contracts\Gateway\TranscriptionGateway;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Prompts\TranscriptionPrompt;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TranscriptionSegment;
use Hypervel\Ai\Responses\Data\TranscriptionUsage;
use Hypervel\Ai\Responses\TranscriptionResponse;
use Hypervel\Support\Collection;
use RuntimeException;

class FakeTranscriptionGateway implements TranscriptionGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayGenerations = false;

    /**
     * Create a transcription gateway with fake responses.
     */
    public function __construct(
        protected Closure|array $responses = [],
    ) {
    }

    /**
     * Generate text from the given audio.
     *
     * @param array<string, mixed> $providerOptions
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
        array $providerOptions = [],
    ): TranscriptionResponse {
        $transcriptionPrompt = new TranscriptionPrompt($audio, $language, $diarize, $provider, $model, $timeout, $providerOptions);

        return $this->nextResponse($provider, $model, $transcriptionPrompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(TranscriptionProvider $provider, string $model, TranscriptionPrompt $prompt): TranscriptionResponse
    {
        // Reserve the entry first; callbacks may yield or throw.
        $index = $this->currentResponseIndex++;

        $response = is_array($this->responses)
            ? ($this->responses[$index] ?? null)
            : call_user_func($this->responses, $prompt);

        return $this->marshalResponse(
            $response,
            $provider,
            $model,
            $prompt
        );
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        TranscriptionProvider $provider,
        string $model,
        TranscriptionPrompt $prompt
    ): TranscriptionResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted transcription generation without a fake response.');
            }

            $response = 'Fake transcription text.';
        }

        if (is_string($response)) {
            return new TranscriptionResponse(
                $response,
                new Collection([
                    new TranscriptionSegment($response, 'Speaker 1', 0.0, 1.0),
                ]),
                new TranscriptionUsage,
                new Meta($provider->name(), $model),
            );
        }

        return $response;
    }

    /**
     * Indicate that an exception should be thrown if any transcription generation is not faked.
     *
     * Tests only. This setting affects the fake gateway shared by requests in the worker.
     */
    public function preventStrayTranscriptions(bool $prevent = true): self
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
