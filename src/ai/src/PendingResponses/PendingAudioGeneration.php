<?php

declare(strict_types=1);

namespace Hypervel\Ai\PendingResponses;

use BackedEnum;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Events\ProviderFailedOver;
use Hypervel\Ai\Exceptions\FailoverableException;
use Hypervel\Ai\Jobs\GenerateAudio;
use Hypervel\Ai\PendingResponses\Concerns\ResolvesProviderOptions;
use Hypervel\Ai\Prompts\QueuedAudioPrompt;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\AudioResponse;
use Hypervel\Ai\Responses\QueuedAudioResponse;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Traits\Conditionable;
use InvalidArgumentException;

class PendingAudioGeneration
{
    use Conditionable;
    use ResolvesProviderOptions;

    protected string $voice = 'default-female';

    protected ?string $instructions = null;

    protected int $timeout = 30;

    /**
     * Create a pending audio generation.
     */
    public function __construct(
        protected string $text,
    ) {
        if (blank($text)) {
            throw new InvalidArgumentException('Text content is required to generate audio.');
        }
    }

    /**
     * Specify a specific voice for the generated audio.
     */
    public function voice(BackedEnum|string $voice): self
    {
        $this->voice = $voice instanceof BackedEnum ? (string) $voice->value : $voice;

        return $this;
    }

    /**
     * Indicate that the voice should be male.
     */
    public function male(): self
    {
        $this->voice = 'default-male';

        return $this;
    }

    /**
     * Indicate that the voice should be female.
     */
    public function female(): self
    {
        $this->voice = 'default-female';

        return $this;
    }

    /**
     * Provide free-form instructions guiding how the audio should sound.
     */
    public function instructions(string $instructions): self
    {
        $this->instructions = $instructions;

        return $this;
    }

    /**
     * Specify the timeout (in seconds) for the audio generation.
     */
    public function timeout(int $seconds = 30): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Generate the audio.
     *
     * @throws FailoverableException if every configured provider fails to generate the audio
     */
    public function generate(Provider|Lab|array|string|null $provider = null, ?string $model = null): AudioResponse
    {
        $providers = Ai::resolveOnDemandProviders(Provider::providerAndModelPairs(
            $provider ?? Config::get('ai.default_for_audio'),
            $model
        ));

        $lastException = null;

        foreach ($providers as [$provider, $model]) {
            $provider = Ai::fakeableAudioProvider($provider);

            [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($provider);

            $model ??= $provider->defaultAudioModel();

            try {
                return $provider->withHeaders($headers)->audio(
                    $this->text,
                    $this->voice,
                    $this->instructions,
                    $model,
                    $this->timeout,
                    $providerOptions
                );
            } catch (FailoverableException $e) {
                $lastException = $e;

                if (Event::hasListeners(ProviderFailedOver::class)) {
                    Event::dispatch(new ProviderFailedOver($provider, $model, $e));
                }

                continue;
            }
        }

        throw $lastException;
    }

    /**
     * Queue the generation of the audio.
     */
    public function queue(Provider|Lab|array|string|null $provider = null, ?string $model = null): QueuedAudioResponse
    {
        if (Ai::audioIsFaked()) {
            Ai::recordAudioGeneration(
                new QueuedAudioPrompt(
                    $this->text,
                    $this->voice,
                    $this->instructions,
                    $provider,
                    $model,
                    $this->timeout,
                    $this->queuedProviderOptions(),
                )
            );
        }

        return new QueuedAudioResponse(
            GenerateAudio::dispatch($this, $provider, $model),
        );
    }
}
