<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Gateway\Ollama\OllamaGateway;
use Hypervel\Ai\Providers\Concerns\GeneratesEmbeddings;
use Hypervel\Ai\Providers\Concerns\GeneratesText;
use Hypervel\Ai\Providers\Concerns\HasEmbeddingGateway;
use Hypervel\Ai\Providers\Concerns\HasTextGateway;
use Hypervel\Ai\Providers\Concerns\StreamsText;
use Hypervel\Contracts\Events\Dispatcher;
use Override;

class OllamaProvider extends Provider implements EmbeddingProvider, TextProvider
{
    use GeneratesEmbeddings;
    use GeneratesText;
    use HasEmbeddingGateway;
    use HasTextGateway;
    use StreamsText;

    protected ?OllamaGateway $ollamaGateway = null;

    /**
     * Create an Ollama provider instance.
     */
    public function __construct(protected array $config, protected Dispatcher $events)
    {
    }

    /**
     * Get the credentials for the Ollama provider (API key is optional).
     */
    #[Override]
    public function providerCredentials(): array
    {
        return [
            'key' => $this->config['key'] ?? '',
        ];
    }

    /**
     * Get the shared Ollama gateway instance.
     */
    protected function ollamaGateway(): OllamaGateway
    {
        return $this->ollamaGateway ??= new OllamaGateway($this->events);
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= $this->ollamaGateway();
    }

    /**
     * Get the provider's embedding gateway.
     */
    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= $this->ollamaGateway();
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'qwen3.5:4b';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'qwen3.5:0.8b';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'gemma4:cloud';
    }

    /**
     * Get the name of the default embeddings model.
     */
    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'nomic-embed-text';
    }

    /**
     * Get the default dimensions of the default embeddings model.
     */
    public function defaultEmbeddingsDimensions(): int
    {
        return $this->config['models']['embeddings']['dimensions'] ?? 768;
    }
}
