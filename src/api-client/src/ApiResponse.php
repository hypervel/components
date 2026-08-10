<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

use Hypervel\ApiClient\Concerns\HasContext;
use Hypervel\ApiClient\Exceptions\InvalidResourceDataException;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Http\Client\Response as HttpClientResponse;
use JsonException;
use Psr\Http\Message\StreamInterface;

/**
 * @implements Arrayable<array-key, mixed>
 */
class ApiResponse extends HttpClientResponse implements Arrayable
{
    use HasContext;

    /**
     * Create an API response from an HTTP client response.
     */
    public static function createFrom(HttpClientResponse $response): static
    {
        $apiResponse = new static($response->toPsrResponse());
        $apiResponse->cookies = $response->cookies;
        $apiResponse->transferStats = $response->transferStats;
        $apiResponse->decoded = $response->decoded;
        $apiResponse->hasDecoded = $response->hasDecoded;
        $apiResponse->decodingFlags = $response->decodingFlags;
        $apiResponse->decodeUsing = $response->decodeUsing;
        $apiResponse->truncateExceptionsAt = $response->truncateExceptionsAt;

        return $apiResponse;
    }

    /**
     * Set the response status.
     */
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $this->response = $this->toPsrResponse()
            ->withStatus($code, $reasonPhrase);

        return $this;
    }

    /**
     * Set the response protocol version.
     */
    public function withProtocolVersion(string $version): static
    {
        $this->response = $this->toPsrResponse()
            ->withProtocolVersion($version);

        return $this;
    }

    /**
     * Determine whether the response has a header.
     */
    public function hasHeader(string $name): bool
    {
        return $this->toPsrResponse()
            ->hasHeader($name);
    }

    /**
     * Set a response header.
     */
    public function withHeader(string $name, mixed $value): static
    {
        $this->response = $this->toPsrResponse()
            ->withHeader($name, $value);

        return $this;
    }

    /**
     * Add a value to a response header.
     */
    public function withAddedHeader(string $name, mixed $value): static
    {
        $this->response = $this->toPsrResponse()
            ->withAddedHeader($name, $value);

        return $this;
    }

    /**
     * Remove a response header.
     */
    public function withoutHeader(string $name): static
    {
        $this->response = $this->toPsrResponse()
            ->withoutHeader($name);

        return $this;
    }

    /**
     * Set the response body.
     */
    public function withBody(StreamInterface $body): static
    {
        $this->response = $this->toPsrResponse()
            ->withBody($body);
        $this->decoded = null;
        $this->hasDecoded = false;
        $this->decodingFlags = 0;

        return $this;
    }

    /**
     * Transform the response into an array.
     *
     * @return array<array-key, mixed>
     * @throws InvalidResourceDataException
     * @throws JsonException
     */
    public function toArray(): array
    {
        $decoded = $this->json();

        if (is_array($decoded)) {
            return $decoded;
        }

        if ($decoded === null && ($this->decodeUsing !== null || $this->hasEmptyOrNullJsonBody())) {
            return [];
        }

        throw new InvalidResourceDataException('The API response body could not be converted to an array.');
    }

    /**
     * Determine whether the body is empty or contains JSON null.
     */
    protected function hasEmptyOrNullJsonBody(): bool
    {
        $body = $this->body();
        $length = strlen($body);
        $offset = strspn($body, " \t\n\r");

        if ($offset === $length) {
            return true;
        }

        if ($length - $offset < 4 || substr_compare($body, 'null', $offset, 4) !== 0) {
            return false;
        }

        $offset += 4;

        return $offset + strspn($body, " \t\n\r", $offset) === $length;
    }

    /**
     * Determine if the given offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        $decoded = $this->json();

        return is_array($decoded) && isset($decoded[$offset]);
    }

    /**
     * Get the value for a given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset];
    }
}
