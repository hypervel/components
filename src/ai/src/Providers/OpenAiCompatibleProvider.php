<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Gateway\TranscriptionGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Gateway\OpenAiCompatible\OpenAiCompatibleGateway;
use Hypervel\Ai\Providers\Concerns\GeneratesEmbeddings;
use Hypervel\Ai\Providers\Concerns\GeneratesText;
use Hypervel\Ai\Providers\Concerns\GeneratesTranscriptions;
use Hypervel\Ai\Providers\Concerns\HasEmbeddingGateway;
use Hypervel\Ai\Providers\Concerns\HasTextGateway;
use Hypervel\Ai\Providers\Concerns\HasTranscriptionGateway;
use Hypervel\Ai\Providers\Concerns\StreamsText;
use Hypervel\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Override;

class OpenAiCompatibleProvider extends Provider implements EmbeddingProvider, TextProvider, TranscriptionProvider
{
    use GeneratesEmbeddings;
    use GeneratesText;
    use GeneratesTranscriptions;
    use HasEmbeddingGateway;
    use HasTextGateway;
    use HasTranscriptionGateway;
    use StreamsText;

    /**
     * Create an OpenAI-compatible provider instance.
     */
    public function __construct(protected array $config, protected Dispatcher $events)
    {
    }

    /**
     * Get the credentials for the underlying AI provider.
     */
    #[Override]
    public function providerCredentials(): array
    {
        return ['key' => $this->config['key'] ?? null];
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new OpenAiCompatibleGateway($this->events);
    }

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= new OpenAiCompatibleGateway($this->events);
    }

    /**
     * Get the provider's transcription gateway.
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new OpenAiCompatibleGateway($this->events);
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? throw new InvalidArgumentException(
            "The [{$this->name()}] openai-compatible provider requires a default text model. Set [models.text.default] in its configuration or pass a model explicitly."
        );
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? $this->defaultTextModel();
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? $this->defaultTextModel();
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? throw new InvalidArgumentException(
            "The [{$this->name()}] openai-compatible provider requires a default embeddings model. Set [models.embeddings.default] in its configuration or pass a model explicitly."
        );
    }

    /**
     * Get the name of the default transcription (STT) model.
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? throw new InvalidArgumentException(
            "The [{$this->name()}] openai-compatible provider requires a default transcription model. Set [models.transcription.default] in its configuration or pass a model explicitly."
        );
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 0;
    }

    /**
     * Determine if the provider may omit dimensions and use a model's native embedding dimensions.
     */
    protected function supportsNativeEmbeddingDimensions(): bool
    {
        return true;
    }
}
