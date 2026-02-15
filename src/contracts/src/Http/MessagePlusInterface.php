<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Http;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

interface MessagePlusInterface extends MessageInterface
{
    public const DEFAULT_PROTOCOL_VERSION = '1.1';

    public function getProtocolVersion(): string;

    public function setProtocolVersion(string $version): static;

    public function withProtocolVersion(string $version): static;

    public function hasHeader(string $name): bool;

    /** @return string[] */
    public function getHeader(string $name): array;

    public function getHeaderLine(string $name): string;

    /** @param string|string[] $value */
    public function setHeader(string $name, string|array $value): static;

    /** @param string|string[] $value */
    public function withHeader(string $name, $value): static;

    /** @param string|string[] $value */
    public function addHeader(string $name, string|array $value): static;

    /** @param string|string[] $value */
    public function withAddedHeader(string $name, $value): static;

    public function unsetHeader(string $name): static;

    public function withoutHeader(string $name): static;

    /** @return array<string, array<string>> */
    public function getHeaders(): array;

    /** @return array<string, array<string>> */
    public function getStandardHeaders(): array;

    /** @param array<string, array<string>|string> $headers */
    public function setHeaders(array $headers): static;

    /** @param array<string, array<string>|string> $headers */
    public function withHeaders(array $headers): static;

    public function shouldKeepAlive(): bool;

    public function getBody(): StreamInterface;

    public function setBody(StreamInterface $body): static;

    public function withBody(StreamInterface $body): static;

    public function toString(bool $withoutBody = false): string;
}
