<?php

declare(strict_types=1);

namespace Hypervel\HttpClient;

use ArrayAccess;
use Closure;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\TransferStats;
use Hyperf\Macroable\Macroable;
use Hypervel\HttpClient\Concerns\DeterminesStatusCode;
use Hypervel\Support\Collection;
use Hypervel\Support\Fluent;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Stringable;

/**
 * @mixin ResponseInterface
 */
class Response implements ArrayAccess, Stringable
{
    use DeterminesStatusCode, Macroable {
        __call as macroCall;
    }

    /**
     * The decoded JSON response.
     */
    protected array $decoded = [];

    /**
     * The request cookies.
     */
    public ?CookieJar $cookies = null;

    /**
     * The transfer stats for the request.
     */
    public ?TransferStats $transferStats = null;

    /**
     * Create a new response instance.
     */
    public function __construct(protected ResponseInterface $response)
    {
    }

    /**
     * Get the body of the response.
     */
    public function body(): string
    {
        return (string) $this->response->getBody();
    }

    /**
     * Get the JSON decoded body of the response as an array or scalar value.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (! $this->decoded) {
            $this->decoded = json_decode($this->body(), true) ?? [];
        }

        if (is_null($key)) {
            return $this->decoded;
        }

        return data_get($this->decoded, $key, $default);
    }

    /**
     * Get the JSON decoded body of the response as an object.
     *
     *  Example:
     *  If the response body is:
     *  [
     *      {"id": 1, "name": "John"},
     *      {"id": 2, "name": "Jane"}
     *  ]
     *
     *  This method will return an array of objects.
     */
    public function object(): array|object|null
    {
        return json_decode($this->body(), false);
    }

    /**
     * Get the JSON decoded body of the response as a collection.
     */
    public function collect(?string $key = null): Collection
    {
        return new Collection($this->json($key));
    }

    /**
     * Get the JSON decoded body of the response as a fluent object.
     */
    public function fluent(?string $key = null): Fluent
    {
        return new Fluent((array) $this->json($key));
    }

    /**
     * Get the body of the response as a PHP resource.
     *
     * @return resource
     *
     * @throws InvalidArgumentException
     */
    public function resource()
    {
        return StreamWrapper::getResource($this->response->getBody());
    }

    /**
     * Get a header from the response.
     */
    public function header(string $header): string
    {
        return $this->response->getHeaderLine($header);
    }

    /**
     * Get the headers from the response.
     */
    public function headers(): array
    {
        return $this->response->getHeaders();
    }

    /**
     * Get the status code of the response.
     */
    public function status(): int
    {
        return (int) $this->response->getStatusCode();
    }

    /**
     * Get the reason phrase of the response.
     */
    public function reason(): string
    {
        return $this->response->getReasonPhrase();
    }

    /**
     * Get the effective URI of the response.
     */
    public function effectiveUri(): ?UriInterface
    {
        return $this->transferStats?->getEffectiveUri();
    }

    /**
     * Determine if the request was successful.
     */
    public function successful(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    /**
     * Determine if the response was a redirect.
     */
    public function redirect(): bool
    {
        return $this->status() >= 300 && $this->status() < 400;
    }

    /**
     * Determine if the response indicates a client or server error occurred.
     */
    public function failed(): bool
    {
        return $this->serverError() || $this->clientError();
    }

    /**
     * Determine if the response indicates a client error occurred.
     */
    public function clientError(): bool
    {
        return $this->status() >= 400 && $this->status() < 500;
    }

    /**
     * Determine if the response indicates a server error occurred.
     */
    public function serverError(): bool
    {
        return $this->status() >= 500;
    }

    /**
     * Execute the given callback if there was a server or client error.
     */
    public function onError(callable $callback): static
    {
        if ($this->failed()) {
            $callback($this);
        }

        return $this;
    }

    /**
     * Get the response cookies.
     */
    public function cookies(): CookieJar
    {
        return $this->cookies;
    }

    /**
     * Get the handler stats of the response.
     */
    public function handlerStats(): array
    {
        return $this->transferStats?->getHandlerStats() ?? [];
    }

    /**
     * Close the stream and any underlying resources.
     */
    public function close(): static
    {
        $this->response->getBody()->close();

        return $this;
    }

    /**
     * Get the underlying PSR response for the response.
     */
    public function toPsrResponse(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * Create an exception if a server or client error occurred.
     */
    public function toException(): ?RequestException
    {
        if ($this->failed()) {
            return new RequestException($this);
        }

        return null;
    }

    /**
     * Throw an exception if a server or client error occurred.
     *
     * @throws RequestException
     */
    public function throw(): static
    {
        $callback = func_get_args()[0] ?? null;

        if ($this->failed()) {
            throw tap($this->toException(), function ($exception) use ($callback) {
                if ($callback && is_callable($callback)) {
                    $callback($this, $exception);
                }
            });
        }

        return $this;
    }

    /**
     * Throw an exception if a server or client error occurred and the given condition evaluates to true.
     *
     * @throws RequestException
     */
    public function throwIf(bool|Closure $condition): static
    {
        return value($condition, $this) ? $this->throw(func_get_args()[1] ?? null) : $this;
    }

    /**
     * Throw an exception if the response status code matches the given code.
     *
     * @param callable|int $statusCode
     *
     * @throws RequestException
     */
    public function throwIfStatus($statusCode): static
    {
        if (is_callable($statusCode)
            && $statusCode($this->status(), $this)) {
            return $this->throw();
        }

        return $this->status() === $statusCode ? $this->throw() : $this;
    }

    /**
     * Throw an exception unless the response status code matches the given code.
     *
     * @param callable|int $statusCode
     *
     * @throws RequestException
     */
    public function throwUnlessStatus($statusCode): static
    {
        if (is_callable($statusCode)) {
            return $statusCode($this->status(), $this) ? $this : $this->throw();
        }

        return $this->status() === $statusCode ? $this : $this->throw();
    }

    /**
     * Throw an exception if the response status code is a 4xx level code.
     *
     * @throws RequestException
     */
    public function throwIfClientError(): static
    {
        return $this->clientError() ? $this->throw() : $this;
    }

    /**
     * Throw an exception if the response status code is a 5xx level code.
     *
     * @throws RequestException
     */
    public function throwIfServerError(): static
    {
        return $this->serverError() ? $this->throw() : $this;
    }

    /**
     * Dump the content from the response.
     *
     * @return $this
     */
    public function dump(?string $key = null): static
    {
        $content = $this->body();

        $json = json_decode($content);

        if (json_last_error() === JSON_ERROR_NONE) {
            $content = $json;
        }

        if (! is_null($key)) {
            dump(data_get($content, $key));
        } else {
            dump($content);
        }

        return $this;
    }

    /**
     * Dump the content from the response and end the script.
     *
     * @return never
     */
    public function dd(?string $key = null): void
    {
        $this->dump($key);

        exit(1);
    }

    /**
     * Dump the headers from the response.
     */
    public function dumpHeaders(): static
    {
        dump($this->headers());

        return $this;
    }

    /**
     * Dump the headers from the response and end the script.
     *
     * @return never
     */
    public function ddHeaders()
    {
        $this->dumpHeaders();

        exit(1);
    }

    /**
     * Determine if the given offset exists.
     *
     * @param string $offset
     */
    public function offsetExists($offset): bool
    {
        return isset($this->json()[$offset]);
    }

    /**
     * Get the value for a given offset.
     *
     * @param string $offset
     */
    public function offsetGet($offset): mixed
    {
        return $this->json()[$offset];
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
        throw new LogicException('Response data may not be mutated using array access.');
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
        throw new LogicException('Response data may not be mutated using array access.');
    }

    /**
     * Get the body of the response.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->body();
    }

    /**
     * Dynamically proxy other methods to the underlying response.
     *
     * @param string $method
     * @param array $parameters
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return static::hasMacro($method)
            ? $this->macroCall($method, $parameters)
            : $this->response->{$method}(...$parameters);
    }
}
