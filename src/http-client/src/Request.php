<?php

declare(strict_types=1);

namespace Hypervel\HttpClient;

use ArrayAccess;
use Hyperf\Collection\Arr;
use Hyperf\Macroable\Macroable;
use Hypervel\Support\Collection;
use LogicException;
use Psr\Http\Message\RequestInterface;

class Request implements ArrayAccess
{
    use Macroable;

    /**
     * The decoded payload for the request.
     */
    protected array $data = [];

    /**
     * Create a new request instance.
     */
    public function __construct(protected RequestInterface $request)
    {
    }

    /**
     * Get the request method.
     */
    public function method(): string
    {
        return $this->request->getMethod();
    }

    /**
     * Get the URL of the request.
     */
    public function url(): string
    {
        return (string) $this->request->getUri();
    }

    /**
     * Determine if the request has a given header.
     */
    public function hasHeader(string $key, mixed $value = null): bool
    {
        if (is_null($value)) {
            return ! empty($this->request->getHeaders()[$key]);
        }

        $headers = $this->headers();

        if (! Arr::has($headers, $key)) {
            return false;
        }

        $value = is_array($value) ? $value : [$value];

        return empty(array_diff($value, $headers[$key]));
    }

    /**
     * Determine if the request has the given headers.
     */
    public function hasHeaders(array|string $headers): bool
    {
        if (is_string($headers)) {
            $headers = [$headers => null];
        }

        foreach ($headers as $key => $value) {
            if (! $this->hasHeader($key, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the values for the header with the given name.
     */
    public function header(string $key): array
    {
        return Arr::get($this->headers(), $key, []);
    }

    /**
     * Get the request headers.
     */
    public function headers(): array
    {
        return $this->request->getHeaders();
    }

    /**
     * Get the body of the request.
     */
    public function body(): string
    {
        return (string) $this->request->getBody();
    }

    /**
     * Determine if the request contains the given file.
     */
    public function hasFile(string $name, ?string $value = null, ?string $filename = null): bool
    {
        if (! $this->isMultipart()) {
            return false;
        }

        return (new Collection($this->data))->reject(function ($file) use ($name, $value, $filename) {
            return $file['name'] != $name
                || ($value && $file['contents'] != $value)
                || ($filename && $file['filename'] != $filename);
        })->count() > 0;
    }

    /**
     * Get the request's data (form parameters or JSON).
     */
    public function data(): array
    {
        if ($this->isForm()) {
            return $this->parameters();
        }
        if ($this->isJson()) {
            return $this->json();
        }

        return $this->data ?? [];
    }

    /**
     * Get the request's form parameters.
     */
    protected function parameters(): array
    {
        if (! $this->data) {
            parse_str($this->body(), $parameters);

            $this->data = $parameters;
        }

        return $this->data;
    }

    /**
     * Get the JSON decoded body of the request.
     */
    protected function json(): array
    {
        if (! $this->data) {
            $this->data = json_decode($this->body(), true) ?? [];
        }

        return $this->data;
    }

    /**
     * Determine if the request is simple form data.
     */
    public function isForm(): bool
    {
        return $this->hasHeader('Content-Type', 'application/x-www-form-urlencoded');
    }

    /**
     * Determine if the request is JSON.
     */
    public function isJson(): bool
    {
        return $this->hasHeader('Content-Type')
            && str_contains($this->header('Content-Type')[0], 'json');
    }

    /**
     * Determine if the request is multipart.
     */
    public function isMultipart(): bool
    {
        return $this->hasHeader('Content-Type')
            && str_contains($this->header('Content-Type')[0], 'multipart');
    }

    /**
     * Set the decoded data on the request.
     */
    public function withData(array $data): Request
    {
        $this->data = $data;

        return $this;
    }

    public function query(): array
    {
        parse_str($this->request->getUri()->getQuery(), $query);

        return $query;
    }

    public function withQuery(array $query = []): Request
    {
        $this->request = $this->request->withUri(
            $this->request->getUri()->withQuery(http_build_query(array_merge($this->query(), $query)))
        );

        return $this;
    }

    /**
     * Get the underlying PSR compliant request instance.
     */
    public function toPsrRequest(): RequestInterface
    {
        return $this->request;
    }

    /**
     * Determine if the given offset exists.
     *
     * @param string $offset
     */
    public function offsetExists($offset): bool
    {
        return isset($this->data()[$offset]);
    }

    /**
     * Get the value for a given offset.
     *
     * @param string $offset
     */
    public function offsetGet($offset): mixed
    {
        return $this->data()[$offset];
    }

    /**
     * Set the value at the given offset.
     *
     * @param string $offset
     *
     * @throws LogicException
     */
    public function offsetSet($offset, mixed $value): void
    {
        throw new LogicException('Request data may not be mutated using array access.');
    }

    /**
     * Unset the value at the given offset.
     *
     * @param string $offset
     *
     * @throws LogicException
     */
    public function offsetUnset($offset): void
    {
        throw new LogicException('Request data may not be mutated using array access.');
    }
}
