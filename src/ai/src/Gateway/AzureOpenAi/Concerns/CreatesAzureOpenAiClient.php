<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\AzureOpenAi\Concerns;

use Hypervel\Ai\Gateway\Concerns\CreatesClient;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Http\Client\PendingRequest;

trait CreatesAzureOpenAiClient
{
    use CreatesClient;

    /**
     * Get an HTTP client for the Azure OpenAI v1-compatible API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        $base = rtrim($config['url'] ?? '', '/');

        return $this->createClient(
            "{$base}/openai/v1",
            ['api-key' => $provider->providerCredentials()['key']],
            $config['headers'] ?? [],
            $timeout ?? 60,
        )->connection($this->httpConnection($provider));
    }
}
