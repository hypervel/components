<?php

declare(strict_types=1);

namespace Hypervel\ApiClient;

use Hypervel\HttpClient\Response as HttpClientResponse;
use Psr\Http\Message\StreamInterface;

class ApiResponse extends HttpClientResponse
{
    use HasContext;

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $this->response = $this->toPsrResponse()
            ->withStatus($code, $reasonPhrase);

        return $this;
    }

    public function withProtocolVersion(string $version): static
    {
        $this->response = $this->toPsrResponse()
            ->withProtocolVersion($version);

        return $this;
    }

    public function hasHeader(string $name): bool
    {
        return $this->toPsrResponse()
            ->hasHeader($name);
    }

    public function withHeader(string $name, mixed $value): static
    {
        $this->response = $this->toPsrResponse()
            ->withHeader($name, $value);

        return $this;
    }

    public function withAddedHeader(string $name, mixed $value): static
    {
        $this->response = $this->toPsrResponse()
            ->withAddedHeader($name, $value);

        return $this;
    }

    public function withoutHeader(string $name): static
    {
        $this->response = $this->toPsrResponse()
            ->withoutHeader($name);

        return $this;
    }

    public function withBody(StreamInterface $body): static
    {
        $this->response = $this->toPsrResponse()
            ->withBody($body);

        return $this;
    }
}
