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
     * Resolve the default request query parameters.
     *
     * @return array<string, mixed>
     */
    protected function defaultQuery(): array
    {
        return [];
    }

    /**
     * Get the request query repository.
     */
    protected function queryRepository(): ArrayRepository
    {
        return $this->queryRepository ??= new ArrayRepository($this->defaultQuery());
    }
}
