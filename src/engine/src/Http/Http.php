<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http;

use Hypervel\Contracts\Engine\Http\Http as HttpContract;
use Stringable;

class Http implements HttpContract
{
    /**
     * Pack an HTTP request into a string.
     */
    public static function packRequest(string $method, string|Stringable $path, array $headers = [], string|Stringable $body = '', string $protocolVersion = HttpContract::DEFAULT_PROTOCOL_VERSION): string
    {
        $headerString = '';
        foreach ($headers as $key => $values) {
            foreach ((array) $values as $value) {
                $headerString .= sprintf("%s: %s\r\n", $key, $value);
            }
        }

        return sprintf(
            "%s %s HTTP/%s\r\n%s\r\n%s",
            $method,
            $path,
            $protocolVersion,
            $headerString,
            $body
        );
    }

    /**
     * Pack an HTTP response into a string.
     */
    public static function packResponse(int $statusCode, string $reasonPhrase = '', array $headers = [], string|Stringable $body = '', string $protocolVersion = HttpContract::DEFAULT_PROTOCOL_VERSION): string
    {
        $headerString = '';
        foreach ($headers as $key => $values) {
            foreach ((array) $values as $value) {
                $headerString .= sprintf("%s: %s\r\n", $key, $value);
            }
        }
        return sprintf(
            "HTTP/%s %s %s\r\n%s\r\n%s",
            $protocolVersion,
            $statusCode,
            $reasonPhrase,
            $headerString,
            $body
        );
    }
}
