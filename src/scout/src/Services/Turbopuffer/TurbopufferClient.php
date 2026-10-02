<?php

declare(strict_types=1);

namespace Hypervel\Scout\Services\Turbopuffer;

use Hypervel\Contracts\Telescope\TelescopeTag;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Http\Client\RequestException;
use Hypervel\Scout\Exceptions\ScoutException;
use Throwable;

/**
 * Turbopuffer API client.
 *
 * The client is shared for the worker lifetime and holds only immutable
 * configuration; each call builds a fresh pending request.
 */
class TurbopufferClient
{
    /**
     * Create a new Turbopuffer client instance.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected Factory $http,
        protected array $config
    ) {
    }

    /**
     * Send a request to Turbopuffer.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function request(string $method, string $uri, array $options = []): array
    {
        $response = $this->pendingRequest()->send($method, $uri, $options);

        if ($response->status() === 202) {
            throw new ScoutException('The Turbopuffer index required by this operation is still building.');
        }

        return $response->throw()->json() ?? [];
    }

    /**
     * Create a pending HTTP request.
     */
    protected function pendingRequest(): PendingRequest
    {
        $baseUrl = $this->config['base_url'] ?? null;

        if (empty($baseUrl)) {
            $baseUrl = sprintf('https://%s.turbopuffer.com', $this->config['region'] ?? 'gcp-us-central1');
        }

        $request = $this->http
            ->baseUrl(rtrim($baseUrl, '/'))
            ->withToken($this->config['api_key'] ?? '')
            ->withTelescopeTags([TelescopeTag::Scout, TelescopeTag::Turbopuffer])
            ->acceptJson()
            ->asJson()
            ->timeout($this->config['timeout'] ?? 60)
            ->connectTimeout($this->config['connect_timeout'] ?? 5);

        $retries = $this->config['retries'] ?? 3;

        if ($retries > 0) {
            $request->retry($retries + 1, 250, function (?Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && in_array($exception->response->status(), [408, 409, 429, 500, 502, 503, 504], true));
            });
        }

        return $request;
    }

    /**
     * Get a Turbopuffer namespace instance.
     */
    public function namespace(string $name): TurbopufferNamespace
    {
        if (! preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $name)) {
            throw new ScoutException("Invalid Turbopuffer namespace [{$name}].");
        }

        return new TurbopufferNamespace($this, $name);
    }
}
