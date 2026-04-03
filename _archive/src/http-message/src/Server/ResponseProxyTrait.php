<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Server;

use Hypervel\HttpMessage\Cookie\Cookie;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

trait ResponseProxyTrait
{
    protected ?ResponseInterface $response = null;

    /**
     * Set the underlying response instance.
     */
    public function setResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    /**
     * Get the underlying response instance.
     */
    public function getResponse(): ResponseInterface
    {
        if (! $this->response instanceof ResponseInterface) {
            throw new RuntimeException('response is invalid.');
        }
        return $this->response;
    }

    /**
     * Retrieve the HTTP protocol version.
     */
    public function getProtocolVersion(): string
    {
        return $this->getResponse()->getProtocolVersion();
    }

    /**
     * Return an instance with the specified HTTP protocol version.
     */
    public function withProtocolVersion(string $version): static
    {
        $this->setResponse($this->getResponse()->withProtocolVersion($version));
        return $this;
    }

    /**
     * Retrieve all message header values.
     */
    public function getHeaders(): array
    {
        return $this->getResponse()->getHeaders();
    }

    /**
     * Determine if a header exists by the given name.
     */
    public function hasHeader(string $name): bool
    {
        return $this->getResponse()->hasHeader($name);
    }

    /**
     * Retrieve a message header value by the given name.
     */
    public function getHeader(string $name): array
    {
        return $this->getResponse()->getHeader($name);
    }

    /**
     * Retrieve a comma-separated string of the values for a single header.
     */
    public function getHeaderLine(string $name): string
    {
        return $this->getResponse()->getHeaderLine($name);
    }

    /**
     * Return an instance with the provided value replacing the specified header.
     */
    public function withHeader(string $name, mixed $value): static
    {
        $this->setResponse($this->getResponse()->withHeader($name, $value));
        return $this;
    }

    /**
     * Return an instance with the specified header appended with the given value.
     */
    public function withAddedHeader(string $name, mixed $value): static
    {
        $this->setResponse($this->getResponse()->withAddedHeader($name, $value));
        return $this;
    }

    /**
     * Return an instance without the specified header.
     */
    public function withoutHeader(string $name): static
    {
        $this->setResponse($this->getResponse()->withoutHeader($name));
        return $this;
    }

    /**
     * Get the body of the message.
     */
    public function getBody(): StreamInterface
    {
        return $this->getResponse()->getBody();
    }

    /**
     * Return an instance with the specified message body.
     */
    public function withBody(StreamInterface $body): static
    {
        $this->setResponse($this->getResponse()->withBody($body));
        return $this;
    }

    /**
     * Get the response status code.
     */
    public function getStatusCode(): int
    {
        return $this->getResponse()->getStatusCode();
    }

    /**
     * Return an instance with the specified status code and reason phrase.
     */
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $this->setResponse($this->getResponse()->withStatus($code, $reasonPhrase));
        return $this;
    }

    /**
     * Get the response reason phrase.
     */
    public function getReasonPhrase(): string
    {
        return $this->getResponse()->getReasonPhrase();
    }

    /**
     * Return an instance with the specified cookie.
     */
    public function withCookie(Cookie $cookie): static
    {
        $response = $this->getResponse();
        if (! method_exists($response, 'withCookie')) {
            throw new RuntimeException('Method withCookie is invalid.');
        }

        $this->setResponse($response->withCookie($cookie));
        return $this;
    }

    /**
     * Retrieve all cookies.
     */
    public function getCookies(): array
    {
        $response = $this->getResponse();
        if (! method_exists($response, 'getCookies')) {
            throw new RuntimeException('Method getCookies is invalid.');
        }
        return $response->getCookies();
    }

    /**
     * Return an instance with the specified trailer.
     */
    public function withTrailer(string $key, mixed $value): static
    {
        $response = $this->getResponse();
        if (! method_exists($response, 'withTrailer')) {
            throw new RuntimeException('Method withTrailer is invalid.');
        }
        $this->setResponse($response->withTrailer($key, $value));
        return $this;
    }

    /**
     * Retrieve a specified trailer value, or null if it does not exist.
     */
    public function getTrailer(string $key): mixed
    {
        $response = $this->getResponse();
        if (! method_exists($response, 'getTrailer')) {
            throw new RuntimeException('Method getTrailer is invalid.');
        }
        return $response->getTrailer($key);
    }

    /**
     * Retrieve all trailer values.
     */
    public function getTrailers(): array
    {
        $response = $this->getResponse();
        if (! method_exists($response, 'getTrailers')) {
            throw new RuntimeException('Method getTrailers is invalid.');
        }
        return $response->getTrailers();
    }
}
