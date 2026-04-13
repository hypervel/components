<?php

declare(strict_types=1);

namespace Hypervel\Socialite;

use GuzzleHttp\Client;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;

/**
 * Base class for all federated login providers.
 *
 * Provides protocol-agnostic infrastructure: HTTP client, request handling,
 * coroutine-safe configuration, state management, and custom parameters.
 * Protocol-specific subclasses (OAuth2, SAML2, etc.) extend this.
 */
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
    public function __construct(
        protected Request $request,
        protected array $guzzle = []
    ) {
    }

    /**
     * Set the baseline provider configuration.
     *
     * Called once at registration time (e.g. from a builder or an extend callback).
     * Writes to the instance property so config survives across coroutines.
     * For per-request overrides, use setConfig() instead.
     */
    public function withConfig(array $config): static
    {
        $this->additionalConfig = $config;

        return $this;
    }

    /**
     * Override provider configuration for the current request.
     *
     * Writes to coroutine context for Swoole safety. Merges with the current
     * effective config so partial overrides preserve baseline keys.
     */
    public function setConfig(array $config): static
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

        return $key ? Arr::get($config, $key, $default) : $config;
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
     */
    public function setRequest(Request $request): static
    {
        $this->request = $request;

        return $this;
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
