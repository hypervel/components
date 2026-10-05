<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\RequestProperties;

use Hypervel\Saloon\Repositories\ArrayRepository;

trait HasQuery
{
    /**
     * The request query parameters.
     */
    protected ?ArrayRepository $queryRepository = null;

    /**
     * The already-encoded query string override.
     */
    protected ?string $queryString = null;

    /**
     * Get the raw query string override, without a leading question mark.
     */
    public function queryString(): ?string
    {
        return $this->queryString ?? $this->defaultQueryString();
    }

    /**
     * Replace the base URL and endpoint query string.
     */
    public function withQueryString(string $query): static
    {
        $this->queryString = $query;

        return $this;
    }

    /**
     * Get the request query parameters.
     *
     * @return array<string, mixed>
     */
    public function queryParameters(): array
    {
        return $this->queryRepository()->all();
    }

    /**
     * Add query parameters to the request.
     *
     * @param array<string, mixed> $parameters
     * @return $this
     */
    public function withQueryParameters(array $parameters): static
    {
        $this->queryRepository()->merge($parameters);

        return $this;
    }

    /**
     * Remove the given query parameters from the request.
     *
     * Only values added through the query parameter methods are removed. Values embedded in the base URL, endpoint
     * or raw query string are unchanged.
     *
     * @param array<int, string>|string $keys
     * @return $this
     */
    public function withoutQueryParameters(array|string $keys): static
    {
        $this->queryRepository()->set(array_diff_key($this->queryParameters(), array_flip((array) $keys)));

        return $this;
    }

    /**
     * Resolve the default request query parameters.
     *
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [];
    }

    /**
     * Resolve the default raw query string override.
     */
    protected function defaultQueryString(): ?string
    {
        return null;
    }

    /**
     * Get the request query repository.
     */
    protected function queryRepository(): ArrayRepository
    {
        return $this->queryRepository ??= new ArrayRepository($this->defaultQuery());
    }
}
