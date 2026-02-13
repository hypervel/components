<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Traits\InteractsWithData;
use League\Uri\QueryString;
use Stringable;

class UriQueryString implements Arrayable, Stringable
{
    use InteractsWithData;

    /**
     * Create a new URI query string instance.
     */
    public function __construct(protected Uri $uri)
    {
    }

    /**
     * Retrieve all data from the instance.
     *
     * @param null|array|mixed $keys
     */
    public function all(mixed $keys = null): array
    {
        $query = $this->toArray();

        if (! $keys) {
            return $query;
        }

        $results = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            Arr::set($results, $key, Arr::get($query, $key));
        }

        return $results;
    }

    /**
     * Retrieve data from the instance.
     */
    protected function data(?string $key = null, mixed $default = null): mixed
    {
        return $this->get($key, $default);
    }

    /**
     * Get a query string parameter.
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        return data_get($this->toArray(), $key, $default);
    }

    /**
     * Get the URL decoded version of the query string.
     */
    public function decode(): string
    {
        return rawurldecode((string) $this);
    }

    /**
     * Get the string representation of the query string.
     */
    public function value(): string
    {
        return (string) $this;
    }

    /**
     * Convert the query string into an array.
     */
    public function toArray(): array
    {
        return QueryString::extract($this->value());
    }

    /**
     * Get the string representation of the query string.
     */
    public function __toString(): string
    {
        return (string) $this->uri->getUri()->getQuery();
    }
}
