<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Concerns;

use Hypervel\Ai\AiManager;
use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Http;

trait CreatesClient
{
    /**
     * Create an HTTP client with the provider's request options.
     *
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $configuredHeaders
     */
    protected function createClient(
        string $baseUrl,
        array $headers = [],
        array $configuredHeaders = [],
        ?int $timeout = 60,
        bool $throw = true,
    ): PendingRequest {
        $headers = collect($headers)
            ->merge($configuredHeaders)
            ->groupBy(fn (mixed $value, string $name): string => strtolower($name), preserveKeys: true)
            ->mapWithKeys(fn (Collection $group): array => [$group->keys()->first() => $group->last()])
            ->all();

        return Http::baseUrl($baseUrl)
            ->withHeaders($headers)
            ->when($timeout !== null, fn (PendingRequest $client): PendingRequest => $client->timeout($timeout))
            ->when($throw, fn (PendingRequest $client): PendingRequest => $client->throw());
    }

    /**
     * Select a boot-registered connection without retaining dynamic provider names.
     */
    protected function httpConnection(Provider $provider): string
    {
        $name = AiManager::HTTP_CONNECTION . '.' . $provider->name();

        return Http::hasConnection($name) ? $name : AiManager::HTTP_CONNECTION;
    }
}
