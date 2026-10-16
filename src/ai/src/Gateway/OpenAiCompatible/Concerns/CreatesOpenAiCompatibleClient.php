<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenAiCompatible\Concerns;

use Hypervel\Ai\Gateway\Concerns\CreatesClient;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Http\Client\PendingRequest;
use InvalidArgumentException;

trait CreatesOpenAiCompatibleClient
{
    use CreatesClient;

    /**
     * Get an HTTP client for the OpenAI-compatible API.
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $key = $provider->providerCredentials()['key'] ?? null;

        return $this->createClient(
            $this->baseUrl($provider),
            filled($key) ? ['Authorization' => 'Bearer ' . $key] : [],
            $provider->additionalConfiguration()['headers'] ?? [],
            $timeout ?? 60,
        )->connection($this->httpConnection($provider));
    }

    /**
     * Get the base URL for the OpenAI-compatible API.
     */
    protected function baseUrl(Provider $provider): string
    {
        $url = $provider->additionalConfiguration()['url'] ?? null;

        if (blank($url)) {
            throw new InvalidArgumentException(
                "The [{$provider->name()}] openai-compatible provider requires a 'url' to be configured."
            );
        }

        return rtrim((string) $url, '/');
    }
}
