<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\Contracts\Gateway\AudioGateway;
use Hypervel\Ai\Contracts\Gateway\TranscriptionGateway;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Gateway\ElevenLabsGateway;
use Hypervel\Ai\Providers\Concerns\GeneratesAudio;
use Hypervel\Ai\Providers\Concerns\GeneratesTranscriptions;
use Hypervel\Ai\Providers\Concerns\HasAudioGateway;
use Hypervel\Ai\Providers\Concerns\HasTranscriptionGateway;
use Hypervel\Contracts\Events\Dispatcher;

class ElevenLabsProvider extends Provider implements AudioProvider, TranscriptionProvider
{
    use GeneratesAudio;
    use GeneratesTranscriptions;
    use HasAudioGateway;
    use HasTranscriptionGateway;

    /**
     * Create an ElevenLabs provider instance.
     */
    public function __construct(
        protected array $config,
        protected Dispatcher $events
    ) {
    }

    /**
     * Get the provider's audio gateway.
     */
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ??= new ElevenLabsGateway;
    }

    /**
     * Get the provider's transcription gateway.
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new ElevenLabsGateway;
    }

    /**
     * Get the name of the default audio (TTS) model.
     */
    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? 'eleven_multilingual_v2';
    }

    /**
     * Get the name of the default transcription (STT) model.
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'scribe_v2';
    }
}
