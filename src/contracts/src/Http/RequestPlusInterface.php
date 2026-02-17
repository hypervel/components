<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

interface RequestPlusInterface extends MessagePlusInterface, RequestInterface
{
    public function getMethod(): string;

    public function setMethod(string $method): static;

    public function withMethod(string $method): static;

    public function getUri(): UriInterface;

    public function setUri(UriInterface|string $uri, ?bool $preserveHost = null): static;

    public function withUri(UriInterface $uri, bool $preserveHost = false): static;

    public function getRequestTarget(): string;

    public function setRequestTarget(string $requestTarget): static;

    public function withRequestTarget(string $requestTarget): static;
}
