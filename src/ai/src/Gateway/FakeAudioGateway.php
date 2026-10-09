<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Closure;
use Hypervel\Ai\Contracts\Gateway\AudioGateway;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Prompts\AudioPrompt;
use Hypervel\Ai\Responses\AudioResponse;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Usage;
use RuntimeException;

class FakeAudioGateway implements AudioGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayGenerations = false;

    /**
     * Create an audio gateway with fake responses.
     */
    public function __construct(
        protected Closure|array $responses = [],
    ) {
    }

    /**
     * Generate audio from the given text.
     *
     * @param array<string, mixed> $providerOptions
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
        array $providerOptions = [],
    ): AudioResponse {
        $audioPrompt = new AudioPrompt($text, $voice, $instructions, $provider, $model, $timeout, $providerOptions);

        return $this->nextResponse($provider, $model, $audioPrompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(AudioProvider $provider, string $model, AudioPrompt $prompt): AudioResponse
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
        AudioProvider $provider,
        string $model,
        AudioPrompt $prompt
    ): AudioResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayGenerations) {
                throw new RuntimeException('Attempted audio generation without a fake response.');
            }

            $response = base64_encode('fake-audio-content');
        }

        if (is_string($response)) {
            return new AudioResponse($response, new Usage, new Meta($provider->name(), $model));
        }

        return $response;
    }

    /**
     * Indicate that an exception should be thrown if any audio generation is not faked.
     *
     * Tests only. This setting affects the fake gateway shared by requests in the worker.
     */
    public function preventStrayAudio(bool $prevent = true): self
    {
        $this->preventStrayGenerations = $prevent;

        return $this;
    }
}
