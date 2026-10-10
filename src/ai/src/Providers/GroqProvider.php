<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Gateway\TranscriptionGateway;
use Hypervel\Ai\Contracts\Providers\SupportsCodeExecution;
use Hypervel\Ai\Contracts\Providers\SupportsWebSearch;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Providers\TranscriptionProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\Groq\GroqGateway;
use Hypervel\Ai\Providers\Concerns\GeneratesText;
use Hypervel\Ai\Providers\Concerns\GeneratesTranscriptions;
use Hypervel\Ai\Providers\Concerns\HasTextGateway;
use Hypervel\Ai\Providers\Concerns\HasTranscriptionGateway;
use Hypervel\Ai\Providers\Concerns\StreamsText;
use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Contracts\Events\Dispatcher;

class GroqProvider extends Provider implements SupportsCodeExecution, SupportsWebSearch, TextProvider, TranscriptionProvider
{
    use GeneratesText;
    use GeneratesTranscriptions;
    use HasTextGateway;
    use HasTranscriptionGateway;
    use StreamsText;

    /**
     * Create a Groq provider instance.
     */
    public function __construct(protected array $config, protected Dispatcher $events)
    {
    }

    /**
     * Get the code execution tool options for the provider.
     */
    public function codeExecutionToolOptions(CodeExecution $codeExecution): array
    {
        return $codeExecution->providerOptions(Lab::Groq);
    }

    /**
     * Get the web search tool options for the provider.
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        return $search->providerOptions(Lab::Groq);
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new GroqGateway($this->events);
    }

    /**
     * Get the provider's transcription gateway.
     */
    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new GroqGateway($this->events);
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'openai/gpt-oss-120b';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'openai/gpt-oss-20b';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'openai/gpt-oss-120b';
    }

    /**
     * Get the name of the default transcription model.
     */
    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'whisper-large-v3-turbo';
    }
}
