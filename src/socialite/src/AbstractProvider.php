<?php

declare(strict_types=1);

namespace Hypervel\Socialite;

use GuzzleHttp\Client;
use Hypervel\Http\Request;
use Hypervel\Socialite\Concerns\HasProviderContext;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use LogicException;
use SensitiveParameter;

abstract class AbstractProvider
{
    use HasProviderContext;

    /**
     * The custom parameters to be sent with the request.
     */
    protected array $parameters = [];

    /**
     * Indicates if the session state should be utilized.
     */
    protected bool $stateless = false;

    /**
     * The provider's baseline configuration.
     *
     * Seeded once at registration/build time via withConfig(). Persists for the
     * worker lifetime as the fallback for getConfig() when no per-request
     * override exists in coroutine context.
     */
    protected array $additionalConfig = [];

    /**
     * Create a new provider instance.
     */
    public function __construct(Request $request, protected array $guzzle = [])
    {
        $this->setRequest($request);
    }

    /**
     * Set the baseline provider configuration.
     *
     * Boot-only. The configuration persists for the worker lifetime and affects
     * every subsequent request. Use setConfig() for per-request overrides.
     */
    public function withConfig(#[SensitiveParameter] array $config): static
    {
        $this->additionalConfig = $config;

        return $this;
    }

    /**
     * Override provider configuration for the current request.
     *
     * The override is isolated to the current coroutine and must be applied
     * independently on the redirect and callback requests.
     */
    public function setConfig(#[SensitiveParameter] array $config): static
    {
        $this->setContext('additionalConfig', array_replace($this->getConfig(), $config));

        return $this;
    }

    /**
     * Get a value from the provider configuration.
     *
     * Reads per-request context first, falls back to baseline instance property.
     */
    protected function getConfig(?string $key = null, mixed $default = null): mixed
    {
        $config = $this->getContext('additionalConfig', $this->additionalConfig);

        return Arr::get($config, $key, $default);
    }

    /**
     * Get an instance of the Guzzle HTTP client.
     */
    protected function getHttpClient(): Client
    {
        return $this->getOrSetContext('httpClient', function () {
            return new Client($this->guzzle);
        });
    }

    /**
     * Set the Guzzle HTTP client instance.
     */
    public function setHttpClient(Client $client): static
    {
        $this->setContext('httpClient', $client);

        return $this;
    }

    /**
     * Set the request instance.
     *
     * Stores the request in coroutine context so cached providers read the
     * current request without leaking it to concurrent coroutines.
     */
    public function setRequest(Request $request): static
    {
        $this->setContext('request', $request);

        return $this;
    }

    /**
     * Get the request instance.
     */
    protected function getRequest(): Request
    {
        $request = $this->getContext('request');

        if (! $request instanceof Request) {
            throw new LogicException(
                'No request is available for this provider. Resolve it through Socialite::driver() or call setRequest().'
            );
        }

        return $request;
    }

    /**
     * Determine if the provider is operating with state.
     */
    protected function usesState(): bool
    {
        return ! $this->isStateless();
    }

    /**
     * Determine if the provider is operating as stateless.
     */
    protected function isStateless(): bool
    {
        return $this->getContext('stateless', $this->stateless);
    }

    /**
     * Indicate that the provider should operate as stateless.
     */
    public function stateless(): static
    {
        $this->setContext('stateless', true);

        return $this;
    }

    /**
     * Get the string used for session state.
     */
    protected function getState(): string
    {
        return Str::random(40);
    }

    /**
     * Set the custom parameters of the request.
     */
    public function with(array $parameters): static
    {
        $this->setContext('parameters', $parameters);

        return $this;
    }

    /**
     * Get the custom parameters of the request.
     */
    protected function getParameters(): array
    {
        return $this->getContext('parameters', $this->parameters);
    }
}
