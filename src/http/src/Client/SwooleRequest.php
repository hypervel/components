<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use Psr\Http\Message\StreamInterface;

final readonly class SwooleRequest
{
    /**
     * Create a prepared native HTTP request.
     *
     * @param array<string, bool|int|string> $constructionSettings
     * @param array{connect_timeout: float, timeout: float, read_timeout: float, body_decompression: bool} $transferSettings
     * @param array<string, string[]> $headers
     */
    public function __construct(
        public string $host,
        public int $port,
        public bool $ssl,
        public array $constructionSettings,
        public array $transferSettings,
        public string $method,
        public string $path,
        public array $headers,
        public StreamInterface $body,
        public string $version,
        public bool $decodeContent,
        public int $delayMicroseconds,
    ) {
    }
}
