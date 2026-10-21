<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\Contracts\Gateway\AudioGateway;
use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Gateway\TranscriptionGateway;
use Hypervel\Ai\Contracts\Providers\AudioProvider;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Gateway\Mistral\MistralGateway;
use Hypervel\Ai\Providers\Concerns\GeneratesAudio;
use Hypervel\Ai\Providers\Concerns\GeneratesEmbeddings;
use Hypervel\Ai\Providers\Concerns\GeneratesText;
use Hypervel\Ai\Providers\Concerns\GeneratesTranscriptions;
use Hypervel\Ai\Providers\Concerns\HasAudioGateway;
use Hypervel\Ai\Providers\Concerns\HasEmbeddingGateway;
use Hypervel\Ai\Providers\Concerns\HasTextGateway;
use Hypervel\Ai\Providers\Concerns\HasTranscriptionGateway;
use Hypervel\Ai\Providers\Concerns\StreamsText;
use Hypervel\Contracts\Events\Dispatcher;

class MistralProvider extends Provider implements AudioProvider, EmbeddingProvider, TextProvider, TranscriptionProvider
{
    use GeneratesAudio;
    use GeneratesEmbeddings;
    use GeneratesText;
    use GeneratesTranscriptions;
    use HasAudioGateway;
    use HasEmbeddingGateway;
    use HasTextGateway;
    use HasTranscriptionGateway;
    use StreamsText;

    protected ?MistralGateway $mistralGateway = null;

    /**
     * Create a Mistral provider instance.
     */
    public function __construct(protected array $config, protected Dispatcher $events)
    {
    }

    /**
     * Get the shared Mistral gateway instance.
     */
    protected function mistralGateway(): MistralGateway
    {
        return $this->mistralGateway ??= new MistralGateway($this->events);
    }

    /**
     * Get the provider's audio gateway.
     */
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ??= $this->mistralGateway();
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= $this->mistralGateway();
    }

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->mistralGateway();
    }

    /**
     * Get the provider's transcription gateway.
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= $this->mistralGateway();
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'mistral-large-2512';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'mistral-small-2603';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'mistral-medium-3-5';
    }

    /**
     * Get the name of the default audio (TTS) model.
     */
    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? 'voxtral-mini-tts-2603';
    }

    /**
     * Get the name of the default transcription (STT) model.
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'voxtral-mini-2602';
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'mistral-embed-2312';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 1024;
    }
}
