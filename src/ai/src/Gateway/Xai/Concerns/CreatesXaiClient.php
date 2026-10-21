<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Xai\Concerns;

use Hypervel\Ai\Gateway\Concerns\CreatesClient;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Http\Client\PendingRequest;

trait CreatesXaiClient
{
    use CreatesClient;

    /**
     * Get an HTTP client for the xAI API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        return $this->createClient(
            $this->baseUrl($provider),
            ['Authorization' => 'Bearer ' . $provider->providerCredentials()['key']],
            $provider->additionalConfiguration()['headers'] ?? [],
            $timeout ?? 60,
        )->connection($this->httpConnection($provider));
    }

    /**
     * Get the base URL for the xAI API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://api.x.ai/v1', '/');
    }
}
