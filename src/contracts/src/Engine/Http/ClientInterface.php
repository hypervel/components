<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http;

interface ClientInterface
{
    /**
     * Configure the client.
     */
    public function set(array $settings): void;

    /**
     * Send an HTTP request and receive its response.
     *
     * @param array<string, string|string[]> $headers
     */
    public function request(string $method = 'GET', string $path = '/', array $headers = [], string $contents = '', string $version = '1.1'): RawResponseInterface;

    /**
     * Send an HTTP request without receiving its response.
     *
     * @param array<string, string|string[]> $headers
     */
    public function send(string $method = 'GET', string $path = '/', array $headers = [], string $contents = '', string $version = '1.1'): void;

    /**
     * Receive the pending HTTP response.
     */
    public function recv(float $timeout = 0): RawResponseInterface;

    /**
     * Close the connection.
     */
    public function close(): void;

    /**
     * Determine if the client is connected.
     */
    public function isConnected(): bool;
}
