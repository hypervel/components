<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http;

use Hypervel\Contracts\Engine\Http\ClientInterface;
use Hypervel\Engine\Exception\HttpClientException;
use Swoole\Coroutine\Http\Client as HttpClient;

class Client extends HttpClient implements ClientInterface
{
    /**
     * Set the client settings.
     */
    public function set(array $settings): bool
    {
        return parent::set($settings);
    }

    /**
     * Send an HTTP request.
     *
     * @param array<string, string|string[]> $headers
     */
    public function request(string $method = 'GET', string $path = '/', array $headers = [], string $contents = '', string $version = '1.1'): RawResponse
    {
        $this->setMethod($method);
        $this->setData($contents);
        $this->setHeaders($this->encodeHeaders($headers));
        $this->execute($path);
        if ($this->errCode !== 0) {
            throw new HttpClientException($this->errMsg, $this->errCode);
        }
        return new RawResponse(
            $this->statusCode,
            $this->decodeHeaders($this->headers ?? []),
            $this->body,
            $version
        );
    }

    /**
     * Decode headers from Swoole format to standard format.
     *
     * @param array<string, string|string[]> $headers
     * @return array<string, string[]>
     */
    private function decodeHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $header) {
            // The key of header is lower case.
            if (is_array($header)) {
                $result[$name] = $header;
            } else {
                $result[$name][] = $header;
            }
        }
        if ($this->set_cookie_headers) {
            $result['set-cookie'] = $this->set_cookie_headers;
        }
        return $result;
    }

    /**
     * Encode headers for Swoole (does not support two-dimensional arrays).
     *
     * @param array<string, string|string[]> $headers
     * @return array<string, string>
     */
    private function encodeHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            $result[$name] = is_array($value) ? implode(',', $value) : $value;
        }

        return $result;
    }
}
