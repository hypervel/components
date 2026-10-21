<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Anthropic\Concerns;

use Hypervel\Ai\Gateway\Concerns\CreatesClient;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Http\Client\PendingRequest;

trait CreatesAnthropicClient
{
    use CreatesClient;

    /**
     * Get an HTTP client for the Anthropic API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        $headers = array_filter([
            'x-api-key' => $provider->providerCredentials()['key'],
            'anthropic-version' => $config['version'] ?? '2023-06-01',
            'anthropic-beta' => $config['anthropic_beta'] ?? 'web-fetch-2025-09-10',
        ]);

        return $this->createClient(
            $this->baseUrl($provider),
            $headers,
            $config['headers'] ?? [],
            $timeout ?? 60,
        )->connection($this->httpConnection($provider));
    }

    /**
     * Get the base URL for the Anthropic API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.anthropic.com/v1', '/');
    }
}
