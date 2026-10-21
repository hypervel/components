<?php

declare(strict_types=1);

namespace Hypervel\Ai\PendingResponses;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Contracts\Files\TranscribableAudio;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Events\ProviderFailedOver;
use Hypervel\Ai\Exceptions\FailoverableException;
use Hypervel\Ai\Files\LocalAudio;
use Hypervel\Ai\Files\StoredAudio;
use Hypervel\Ai\Jobs\GenerateTranscription;
use Hypervel\Ai\PendingResponses\Concerns\ResolvesProviderOptions;
use Hypervel\Ai\Prompts\QueuedTranscriptionPrompt;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\QueuedTranscriptionResponse;
use Hypervel\Ai\Responses\TranscriptionResponse;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Traits\Conditionable;
use LogicException;

class PendingTranscriptionGeneration
{
    use Conditionable;
    use ResolvesProviderOptions;

    protected ?string $language = null;

    protected bool $diarize = false;

    protected int $timeout = 30;

    /**
     * Create a pending transcription generation.
     */
    public function __construct(
        protected TranscribableAudio $audio,
    ) {
    }

    /**
     * Specify the language (ISO-639-1) of the audio being transcribed.
     */
    public function language(string $language): self
    {
        $this->language = $language;

        return $this;
    }

    /**
     * Indicate that the transcript should be diarized.
     */
    public function diarize(bool $diarize = true): self
    {
        $this->diarize = $diarize;

        return $this;
    }

    /**
     * Specify the timeout (in seconds) for the transcription generation.
     */
    public function timeout(int $seconds = 30): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Generate the transcription.
     *
     * @throws FailoverableException if every configured provider fails to generate the transcription
     */
    public function generate(Provider|Lab|array|string|null $provider = null, ?string $model = null): TranscriptionResponse
    {
        $providers = Ai::resolveOnDemandProviders(Provider::providerAndModelPairs(
            $provider ?? Config::get('ai.default_for_transcription'),
            $model
        ));

        $lastException = null;

        foreach ($providers as [$provider, $model]) {
            $provider = Ai::fakeableTranscriptionProvider($provider);

            $model ??= $provider->defaultTranscriptionModel();

            [$providerOptions, $headers] = $this->resolveProviderOptionsAndHeaders($provider);

            $provider = $provider->withHeaders($headers);

            try {
                return $provider->transcribe($this->audio, $this->language, $this->diarize, $model, $this->timeout, $providerOptions);
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
     * Queue the generation of the transcription.
     *
     * @throws LogicException if the audio attachment is not a local audio or an audio file stored on a filesystem disk
     */
    public function queue(Provider|Lab|array|string|null $provider = null, ?string $model = null): QueuedTranscriptionResponse
    {
        if (! $this->audio instanceof StoredAudio
            && ! $this->audio instanceof LocalAudio) {
            throw new LogicException('Only local audio or audio stored on a filesystem disk may be attachments for queued transcription generations.');
        }

        if (Ai::transcriptionsAreFaked()) {
            Ai::recordTranscriptionGeneration(
                new QueuedTranscriptionPrompt(
                    $this->audio,
                    $this->language,
                    $this->diarize,
                    $provider,
                    $model,
                    $this->timeout,
                    $this->queuedProviderOptions(),
                )
            );
        }

        return new QueuedTranscriptionResponse(
            GenerateTranscription::dispatch($this, $provider, $model),
        );
    }
}
