<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Server;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Stringable;
use Hypervel\Contracts\Http\ResponsePlusInterface;

class ResponsePlusProxy implements ResponsePlusInterface, Stringable
{
    /**
     * Create a new response proxy instance.
     */
    public function __construct(protected ResponseInterface $response)
    {
    }

    /**
     * Get the string representation of the response.
     */
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Dynamically proxy method calls to the underlying response.
     */
    public function __call(string $name, array $arguments): mixed
    {
        if (str_starts_with($name, 'with')) {
            return new static($this->response->{$name}(...$arguments));
        }

        if (str_starts_with($name, 'get')) {
            return $this->response->{$name}(...$arguments);
        }

        if (str_starts_with($name, 'set')) {
            $this->response->{$name}(...$arguments);
            return $this;
        }

        throw new InvalidArgumentException(sprintf('The method %s is not supported.', $name));
    }

    /**
     * Retrieve all cookies from the underlying response.
     */
    public function getCookies(): array
    {
        if (method_exists($this->response, 'getCookies')) {
            return $this->response->getCookies();
        }

        return [];
    }

    /**
     * Retrieve the HTTP protocol version.
     */
    public function getProtocolVersion(): string
    {
        return $this->response->getProtocolVersion();
    }

    /**
     * Set the HTTP protocol version (mutable).
     */
    public function setProtocolVersion(string $version): static
    {
        $this->response = $this->response->withProtocolVersion($version);
        return $this;
    }

    /**
     * Return an instance with the specified HTTP protocol version.
     */
    public function withProtocolVersion(string $version): static
    {
        return new static($this->response->withProtocolVersion($version));
    }

    /**
     * Determine if a header exists by the given name.
     */
    public function hasHeader(string $name): bool
    {
        return $this->response->hasHeader($name);
    }

    /**
     * Retrieve a message header value by the given name.
     */
    public function getHeader(string $name): array
    {
        return $this->response->getHeader($name);
    }

    /**
     * Retrieve a comma-separated string of the values for a single header.
     */
    public function getHeaderLine(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    /**
     * Set a header value (mutable).
     *
     * @param string|string[] $value
     */
    public function setHeader(string $name, string|array $value): static
    {
        $this->response = $this->response->withHeader($name, $value);
        return $this;
    }

    /**
     * Return an instance with the provided value replacing the specified header.
     *
     * @param string|string[] $value
     */
    public function withHeader(string $name, $value): static
    {
        return new static($this->response->withHeader($name, $value));
    }

    /**
     * Add a header value (mutable).
     *
     * @param string|string[] $value
     */
    public function addHeader(string $name, string|array $value): static
    {
        $this->response = $this->response->withAddedHeader($name, $value);
        return $this;
    }

    /**
     * Return an instance with the specified header appended with the given value.
     *
     * @param string|string[] $value
     */
    public function withAddedHeader(string $name, $value): static
    {
        return new static($this->response->withAddedHeader($name, $value));
    }

    /**
     * Remove a header by name (mutable).
     */
    public function unsetHeader(string $name): static
    {
        $this->response = $this->response->withoutHeader($name);
        return $this;
    }

    /**
     * Return an instance without the specified header.
     */
    public function withoutHeader(string $name): static
    {
        return new static($this->response->withoutHeader($name));
    }

    /**
     * Retrieve all message header values.
     */
    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    /**
     * Retrieve all headers with default Connection and Content-Length added if missing.
     */
    public function getStandardHeaders(): array
    {
        $headers = $this->getHeaders();
        if (! $this->hasHeader('connection')) {
            $headers['Connection'] = [$this->shouldKeepAlive() ? 'keep-alive' : 'close'];
        }
        if (! $this->hasHeader('content-length')) {
            $headers['Content-Length'] = [(string) ($this->getBody()->getSize() ?? 0)];
        }
        return $headers;
    }

    /**
     * Replace all headers (mutable).
     */
    public function setHeaders(array $headers): static
    {
        foreach ($this->getHeaders() as $key => $value) {
            $this->unsetHeader($key);
        }

        foreach ($headers as $key => $value) {
            $this->setHeader($key, $value);
        }

        return $this;
    }

    /**
     * Return an instance with the provided headers replacing all existing headers.
     */
    public function withHeaders(array $headers): static
    {
        return new static($this->setHeaders($headers)->response);
    }

    /**
     * Determine if the connection should be kept alive.
     */
    public function shouldKeepAlive(): bool
    {
        return strtolower($this->getHeaderLine('Connection')) === 'keep-alive';
    }

    /**
     * Get the body of the message.
     */
    public function getBody(): StreamInterface
    {
        return $this->response->getBody();
    }

    /**
     * Set the body of the message (mutable).
     */
    public function setBody(StreamInterface $body): static
    {
        $this->response = $this->response->withBody($body);
        return $this;
    }

    /**
     * Return an instance with the specified message body.
     */
    public function withBody(StreamInterface $body): static
    {
        return new static($this->response->withBody($body));
    }

    /**
     * Get the string representation of the response.
     */
    public function toString(bool $withoutBody = false): string
    {
        $headerString = '';
        foreach ($this->getStandardHeaders() as $key => $values) {
            foreach ($values as $value) {
                $headerString .= sprintf("%s: %s\r\n", $key, $value);
            }
        }
        return sprintf(
            "HTTP/%s %s %s\r\n%s\r\n%s",
            $this->getProtocolVersion(),
            $this->getStatusCode(),
            $this->getReasonPhrase(),
            $headerString,
            $withoutBody ? '' : $this->getBody()
        );
    }

    /**
     * Get the response status code.
     */
    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * Get the response reason phrase.
     */
    public function getReasonPhrase(): string
    {
        return $this->response->getReasonPhrase();
    }

    /**
     * Set the response status code (mutable).
     */
    public function setStatus(int $code, string $reasonPhrase = ''): static
    {
        $this->response = $this->response->withStatus($code, $reasonPhrase);
        return $this;
    }

    /**
     * Return an instance with the specified status code and reason phrase.
     */
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        return new static($this->response->withStatus($code, $reasonPhrase));
    }
}
