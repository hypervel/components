<?php

declare(strict_types=1);

namespace Hypervel\HttpMessage\Server;

use Hypervel\Contracts\Engine\Http\Writable;
use Hypervel\HttpMessage\Base\Response as BaseResponse;
use Hypervel\HttpMessage\Cookie\Cookie;
use Hypervel\HttpMessage\Server\Chunk\Chunkable;
use Hypervel\HttpMessage\Server\Chunk\HasChunk;
use Hypervel\HttpMessage\Stream\SwooleStream;

class Response extends BaseResponse implements Chunkable
{
    use HasChunk;

    protected array $cookies = [];

    protected array $trailers = [];

    protected ?Writable $connection = null;

    /**
     * Return an instance with the given body content.
     */
    public function withContent(string $content): static
    {
        $new = clone $this;
        $new->stream = new SwooleStream($content);
        return $new;
    }

    /**
     * Return an instance with the specified cookie.
     */
    public function withCookie(Cookie $cookie): static
    {
        $clone = clone $this;
        $clone->cookies[$cookie->getDomain()][$cookie->getPath()][$cookie->getName()] = $cookie;
        return $clone;
    }

    /**
     * Set a cookie on the response (mutable).
     */
    public function setCookie(Cookie $cookie): static
    {
        $this->cookies[$cookie->getDomain()][$cookie->getPath()][$cookie->getName()] = $cookie;
        return $this;
    }

    /**
     * Retrieve all cookies.
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Return an instance with the specified trailer.
     */
    public function withTrailer(string $key, mixed $value): static
    {
        $new = clone $this;
        $new->trailers[$key] = $value;
        return $new;
    }

    /**
     * Retrieve a specified trailer value, or null if it does not exist.
     */
    public function getTrailer(string $key): mixed
    {
        return $this->trailers[$key] ?? null;
    }

    /**
     * Retrieve all trailer values.
     */
    public function getTrailers(): array
    {
        return $this->trailers;
    }

    /**
     * Set the writable connection for the response.
     */
    public function setConnection(Writable $connection): static
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * Get the writable connection for the response.
     */
    public function getConnection(): ?Writable
    {
        return $this->connection;
    }
}
