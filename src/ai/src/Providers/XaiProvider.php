<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\Contracts\Gateway\ImageGateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\ImageProvider;
use Hypervel\Ai\Contracts\Providers\SupportsCodeExecution;
use Hypervel\Ai\Contracts\Providers\SupportsFileSearch;
use Hypervel\Ai\Contracts\Providers\SupportsWebSearch;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\Xai\XaiGateway;
use Hypervel\Ai\Gateway\Xai\XaiImageGateway;
use Hypervel\Ai\Providers\Concerns\GeneratesImages;
use Hypervel\Ai\Providers\Concerns\GeneratesText;
use Hypervel\Ai\Providers\Concerns\HasImageGateway;
use Hypervel\Ai\Providers\Concerns\HasTextGateway;
use Hypervel\Ai\Providers\Concerns\StreamsText;
use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\FileSearch;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Contracts\Events\Dispatcher;
use InvalidArgumentException;

class XaiProvider extends Provider implements ImageProvider, SupportsCodeExecution, SupportsFileSearch, SupportsWebSearch, TextProvider
{
    use GeneratesImages;
    use GeneratesText;
    use HasImageGateway;
    use HasTextGateway;
    use StreamsText;

    /**
     * Create an xAI provider instance.
     */
    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {
    }

    /**
     * Get the code execution tool options for the provider.
     */
    public function codeExecutionToolOptions(CodeExecution $codeExecution): array
    {
        return $codeExecution->providerOptions(Lab::xAI);
    }

    /**
     * Get the file search tool options for the provider.
     */
    public function fileSearchToolOptions(FileSearch $search): array
    {
        if (filled($search->filters)) {
            throw new InvalidArgumentException('xAI does not support file search metadata filters.');
        }

        return array_filter([
            'vector_store_ids' => $search->ids(),
        ]) + $search->providerOptions(Lab::xAI);
    }

    /**
     * Get the web search tool options for the provider.
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        $options = $search->providerOptions(Lab::xAI);

        return array_filter([
            'allowed_domains' => filled($search->allowedDomains) ? $search->allowedDomains : null,
        ]) + $options;
    }

    /**
     * Get the provider's text gateway.
     */
    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new XaiGateway($this->events);
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'grok-4.7';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'grok-4.20-non-reasoning';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'grok-4.7';
    }

    /**
     * Get the provider's image gateway.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new XaiImageGateway;
    }

    /**
     * Get the name of the default image model.
     */
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'grok-imagine-image-2.0';
    }

    /**
     * Get the default / normalized image options for the provider.
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        return array_filter([
            'aspect_ratio' => match ($size) {
                '1:1' => '1:1',
                '2:3' => '2:3',
                '3:2' => '3:2',
                null => null,
                default => $size,
            },
            'resolution' => match ($quality) {
                'low', '1K' => '1k',
                'medium', '2K' => '2k',
                'high', '4K' => '2k',
                default => null,
            },
        ]);
    }
}
