<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Support\Traits\ForwardsCalls;
use InvalidArgumentException;
use JsonSerializable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Laravel-compatible JsonResponse wrapper around PSR-7 ResponseInterface.
 *
 * This class provides the Laravel JsonResponse API while internally delegating
 * to a PSR-7 response for Swoole compatibility. It enables features like the
 * `$original` property and `withResponse()` customization in HTTP Resources.
 */
class JsonResponse implements ResponseInterface
{
    use ForwardsCalls;

    /**
     * The original data before JSON encoding.
     */
    public mixed $original = null;

    /**
     * The JSON encoding options.
     */
    protected int $encodingOptions = 0;

    /**
     * The underlying PSR-7 response.
     */
    protected ResponseInterface $response;

    /**
     * Create a new JSON response instance.
     */
    public function __construct(
        ResponseInterface $response,
        mixed $originalData = null,
        int $encodingOptions = 0
    ) {
        $this->response = $response;
        $this->original = $originalData;
        $this->encodingOptions = $encodingOptions;
    }

    /**
     * Get the underlying PSR-7 response.
     */
    public function toPsr7(): ResponseInterface
    {
        return $this->response;
    }

    /**
     * Get the JSON decoded data from the response.
     */
    public function getData(bool $assoc = false, int $depth = 512): mixed
    {
        return json_decode((string) $this->response->getBody(), $assoc, $depth);
    }

    /**
     * Set the data to be sent as JSON.
     *
     * @throws InvalidArgumentException
     */
    public function setData(mixed $data = []): static
    {
        $this->original = $data;

        $json = match (true) {
            $data instanceof Jsonable => $data->toJson($this->encodingOptions),
            $data instanceof JsonSerializable => json_encode($data->jsonSerialize(), $this->encodingOptions | JSON_THROW_ON_ERROR),
            $data instanceof Arrayable => json_encode($data->toArray(), $this->encodingOptions | JSON_THROW_ON_ERROR),
            default => json_encode($data, $this->encodingOptions | JSON_THROW_ON_ERROR),
        };

        $body = $this->response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $newBody = new \Hyperf\HttpMessage\Stream\SwooleStream($json);
        $this->response = $this->response->withBody($newBody);

        return $this;
    }

    /**
     * Get the JSON encoding options.
     */
    public function getEncodingOptions(): int
    {
        return $this->encodingOptions;
    }

    /**
     * Set the JSON encoding options.
     */
    public function setEncodingOptions(int $options): static
    {
        $this->encodingOptions = $options;

        if ($this->original !== null) {
            $this->setData($this->original);
        }

        return $this;
    }

    /**
     * Set a header on the response.
     */
    public function header(string $key, string|array $values, bool $replace = true): static
    {
        $this->response = $replace
            ? $this->response->withHeader($key, $values)
            : $this->response->withAddedHeader($key, $values);

        return $this;
    }

    /**
     * Add multiple headers to the response.
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $key => $value) {
            $this->response = $this->response->withHeader($key, $value);
        }

        return $this;
    }

    /**
     * Set the response status code.
     */
    public function setStatusCode(int $code, string $text = ''): static
    {
        $this->response = $this->response->withStatus($code, $text);

        return $this;
    }

    /**
     * Get the status code.
     */
    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * Get the content of the response.
     */
    public function content(): string
    {
        return (string) $this->response->getBody();
    }

    // =========================================================================
    // PSR-7 ResponseInterface Implementation (delegates to wrapped response)
    // =========================================================================

    public function getProtocolVersion(): string
    {
        return $this->response->getProtocolVersion();
    }

    public function withProtocolVersion(string $version): static
    {
        $clone = clone $this;
        $clone->response = $this->response->withProtocolVersion($version);
        return $clone;
    }

    public function getHeaders(): array
    {
        return $this->response->getHeaders();
    }

    public function hasHeader(string $name): bool
    {
        return $this->response->hasHeader($name);
    }

    public function getHeader(string $name): array
    {
        return $this->response->getHeader($name);
    }

    public function getHeaderLine(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function withHeader(string $name, $value): static
    {
        $clone = clone $this;
        $clone->response = $this->response->withHeader($name, $value);
        return $clone;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $clone = clone $this;
        $clone->response = $this->response->withAddedHeader($name, $value);
        return $clone;
    }

    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        $clone->response = $this->response->withoutHeader($name);
        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->response->getBody();
    }

    public function withBody(StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->response = $this->response->withBody($body);
        return $clone;
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $clone = clone $this;
        $clone->response = $this->response->withStatus($code, $reasonPhrase);
        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->response->getReasonPhrase();
    }

    /**
     * Dynamically pass method calls to the underlying response.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->response, $method, $parameters);
    }
}
