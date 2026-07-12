<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http;

use Stringable;

interface Http
{
    public const DEFAULT_PROTOCOL_VERSION = '1.1';

    /**
     * Pack an HTTP request.
     */
    public static function packRequest(string $method, string|Stringable $path, array $headers = [], string|Stringable $body = '', string $protocolVersion = self::DEFAULT_PROTOCOL_VERSION): string;

    /**
     * Pack an HTTP response.
     */
    public static function packResponse(int $statusCode, string $reasonPhrase = '', array $headers = [], string|Stringable $body = '', string $protocolVersion = self::DEFAULT_PROTOCOL_VERSION): string;
}
