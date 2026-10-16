<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenRouter\Concerns;

use Hypervel\Ai\Gateway\Concerns\CreatesClient;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Http\Client\PendingRequest;

trait CreatesOpenRouterClient
{
    use CreatesClient;

    /**
     * Get an HTTP client for the OpenRouter API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        return $this->createClient(
            $this->baseUrl($provider),
            array_filter([
                'Authorization' => 'Bearer ' . $provider->providerCredentials()['key'],
                'HTTP-Referer' => $config['http_referer'] ?? null,
                'X-OpenRouter-Title' => $config['x_title'] ?? null,
            ]),
            $config['headers'] ?? [],
            $timeout ?? 60,
        )->connection($this->httpConnection($provider));
    }

    /**
     * Get the base URL for the OpenRouter API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://openrouter.ai/api/v1', '/');
    }
}
