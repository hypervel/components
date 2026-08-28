<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\RequestProperties;

use Hypervel\Saloon\Repositories\ArrayRepository;

trait HasHeaders
{
    /**
     * The request headers.
     */
    protected ?ArrayRepository $headerRepository = null;

    /**
     * Get the request headers.
     *
     * @return array<string, mixed>
     */
    public function headers(): array
    {
        return $this->headerRepository()->all();
    }

    /**
     * Determine if the request contains the given header.
     */
    public function hasHeader(string $name): bool
    {
        foreach (array_keys($this->headers()) as $header) {
            if (strcasecmp($header, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add headers to the request.
     *
     * @param array<string, mixed> $headers
     * @return $this
     */
    public function withHeaders(array $headers): static
    {
        $this->headerRepository()->set(array_merge_recursive($this->headers(), $headers));

        return $this;
    }

    /**
     * Add a header to the request.
     *
     * @return $this
     */
    public function withHeader(string $name, mixed $value): static
    {
        return $this->withHeaders([$name => $value]);
    }

    /**
     * Replace matching headers on the request.
     *
     * @param array<string, mixed> $headers
     * @return $this
     */
    public function replaceHeaders(array $headers): static
    {
        $resolvedHeaders = [];
        $headerNames = [];

        foreach (array_merge($this->headers(), $headers) as $name => $value) {
            $normalizedName = strtolower($name);

            if (isset($headerNames[$normalizedName])) {
                unset($resolvedHeaders[$headerNames[$normalizedName]]);
            }

            $resolvedHeaders[$name] = $value;
            $headerNames[$normalizedName] = $name;
        }

        $this->headerRepository()->set($resolvedHeaders);

        return $this;
    }

    /**
     * Specify the request content type.
     *
     * @return $this
     */
    public function contentType(string $contentType): static
    {
        return $this->replaceHeaders(['Content-Type' => $contentType]);
    }

    /**
     * Indicate that JSON should be returned by the server.
     *
     * @return $this
     */
    public function acceptJson(): static
    {
        return $this->accept('application/json');
    }

    /**
     * Indicate the type of content that should be returned by the server.
     *
     * @return $this
     */
    public function accept(string $contentType): static
    {
        return $this->replaceHeaders(['Accept' => $contentType]);
    }

    /**
     * Specify the request user agent.
     *
     * @return $this
     */
    public function withUserAgent(bool|string $userAgent): static
    {
        return $this->replaceHeaders(['User-Agent' => trim((string) $userAgent)]);
    }

    /**
     * Resolve the default request headers.
     *
     * @return array<string, mixed>
     */
    protected function defaultHeaders(): array
    {
        return [];
    }

    /**
     * Get the request header repository.
     */
    protected function headerRepository(): ArrayRepository
    {
        return $this->headerRepository ??= new ArrayRepository($this->defaultHeaders());
    }
}
