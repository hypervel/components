<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers;

use Hypervel\Ai\Contracts\Gateway\FileGateway;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Contracts\Providers\SupportsCodeExecution;
use Hypervel\Ai\Contracts\Providers\SupportsToolSearch;
use Hypervel\Ai\Contracts\Providers\SupportsWebFetch;
use Hypervel\Ai\Contracts\Providers\SupportsWebSearch;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Gateway\Anthropic\AnthropicFileGateway;
use Hypervel\Ai\Providers\Concerns\GeneratesText;
use Hypervel\Ai\Providers\Concerns\HasFileGateway;
use Hypervel\Ai\Providers\Concerns\HasTextGateway;
use Hypervel\Ai\Providers\Concerns\ManagesFiles;
use Hypervel\Ai\Providers\Concerns\StreamsText;
use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\WebFetch;
use Hypervel\Ai\Providers\Tools\WebSearch;

class AnthropicProvider extends Provider implements FileProvider, SupportsCodeExecution, SupportsToolSearch, SupportsWebFetch, SupportsWebSearch, TextProvider
{
    use GeneratesText;
    use HasFileGateway;
    use HasTextGateway;
    use ManagesFiles;
    use StreamsText;

    /**
     * Get the code execution tool options for the provider.
     */
    public function codeExecutionToolOptions(CodeExecution $codeExecution): array
    {
        return $codeExecution->providerOptions(Lab::Anthropic);
    }

    /**
     * Get the web fetch tool options for the provider.
     */
    public function webFetchToolOptions(WebFetch $fetch): array
    {
        return array_filter([
            'max_uses' => $fetch->maxSearches,
            'allowed_domains' => $fetch->allowedDomains === []
                ? null
                : $fetch->allowedDomains,
        ]) + $fetch->providerOptions(Lab::Anthropic);
    }

    /**
     * Get the web search tool options for the provider.
     */
    public function webSearchToolOptions(WebSearch $search): array
    {
        return array_filter([
            'max_uses' => $search->maxSearches,
            'allowed_domains' => $search->allowedDomains === []
                ? null
                : $search->allowedDomains,
            'user_location' => $search->hasLocation()
                ? array_filter([
                    'type' => 'approximate',
                    'city' => $search->city,
                    'region' => $search->region,
                    'country' => $search->country,
                ])
                : null,
        ]) + $search->providerOptions(Lab::Anthropic);
    }

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'claude-sonnet-5-5';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'claude-haiku-5-5';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'claude-opus-5-5';
    }

    /**
     * Get the provider's file gateway.
     */
    public function fileGateway(): FileGateway
    {
        return $this->fileGateway ??= new AnthropicFileGateway;
    }
}
