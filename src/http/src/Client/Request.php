<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use ArrayAccess;
use Hypervel\Support\Collection;
use Hypervel\Support\Json;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Uri;
use InvalidArgumentException;
use JsonException;
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
     * Determine whether the request data has been decoded.
     */
    protected bool $hasDecodedData = false;

    /**
     * The attribute data passed when building the PendingRequest.
     */
    protected array $attributes = [];

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
     * Get the request URI as a URI instance.
     */
    public function uri(): Uri
    {
        return Uri::of($this->url());
    }

    /**
     * Determine if the request has a given header.
     */
    public function hasHeader(string $key, mixed $value = null): bool
    {
        if (! $this->request->hasHeader($key)) {
            return false;
        }

        if (is_null($value)) {
            return true;
        }

        $value = is_array($value) ? $value : [$value];

        return empty(array_diff($value, $this->request->getHeader($key)));
    }

    /**
     * Determine if the request has the given headers.
     */
    public function hasHeaders(array|string $headers): bool
    {
        if (is_string($headers)) {
            $headers = [$headers => null];
        }

        return array_all($headers, fn ($value, $key) => $this->hasHeader($key, $value));
    }

    /**
     * Get the values for the header with the given name.
     */
    public function header(string $key): array
    {
        return $this->request->getHeader($key);
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

        return (new Collection($this->data))->contains(function ($file) use ($name, $value, $filename) {
            return ($file['name'] ?? null) === $name
                && ($value === null || ($file['contents'] ?? null) === $value)
                && ($filename === null || ($file['filename'] ?? null) === $filename);
        });
    }

    /**
     * Get the request's data (form parameters or JSON).
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function data(): array
    {
        if ($this->isForm()) {
            return $this->parameters();
        }
        if ($this->isJson()) {
            return $this->json();
        }

        return $this->data;
    }

    /**
     * Get the request's form parameters.
     */
    protected function parameters(): array
    {
        if (! $this->hasDecodedData && $this->data === []) {
            parse_str($this->body(), $parameters);

            $this->data = $parameters;
            $this->hasDecodedData = true;
        }

        return $this->data;
    }

    /**
     * Get the JSON decoded body of the request.
     *
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    protected function json(): array
    {
        if (! $this->hasDecodedData && $this->data === []) {
            $body = $this->body();

            if ($body === '') {
                $this->hasDecodedData = true;

                return $this->data;
            }

            $data = Json::decode($body);

            if (! is_array($data)) {
                throw new InvalidArgumentException('The request JSON body must decode to an array.');
            }

            $this->data = $data;
            $this->hasDecodedData = true;
        }

        return $this->data;
    }

    /**
     * Determine if the request is simple form data.
     */
    public function isForm(): bool
    {
        return $this->mediaType() === 'application/x-www-form-urlencoded';
    }

    /**
     * Determine if the request is JSON.
     */
    public function isJson(): bool
    {
        return str_contains($this->mediaType(), 'json');
    }

    /**
     * Determine if the request is multipart.
     */
    public function isMultipart(): bool
    {
        return str_contains($this->mediaType(), 'multipart');
    }

    /**
     * Get the normalized request media type.
     */
    protected function mediaType(): string
    {
        if (! $this->hasHeader('Content-Type')) {
            return '';
        }

        return strtolower(trim(explode(';', $this->header('Content-Type')[0], 2)[0]));
    }

    /**
     * Set the logical data on the request.
     *
     * This does not mark the body as decoded, so a final JSON or form body may replace it.
     */
    public function withData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Get the request query parameters.
     */
    public function query(): array
    {
        parse_str($this->request->getUri()->getQuery(), $query);

        return $query;
    }

    /**
     * Add query parameters to the request.
     */
    public function withQuery(array $query = []): static
    {
        $this->request = $this->request->withUri(
            $this->request->getUri()->withQuery(http_build_query(array_replace($this->query(), $query)))
        );

        return $this;
    }

    /**
     * Remove query parameters from the request.
     */
    public function withoutQuery(array|string $keys = []): static
    {
        $query = $this->query();
        foreach ((array) $keys as $key) {
            unset($query[$key]);
        }
        $this->request = $this->request->withUri($this->request->getUri()->withQuery(http_build_query($query)));

        return $this;
    }

    /**
     * Get the attribute data from the request.
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * Set the request's attribute data.
     */
    public function setRequestAttributes(array $attributes): static
    {
        $this->attributes = $attributes;

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

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
