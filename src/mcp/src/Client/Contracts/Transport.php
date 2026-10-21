<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Contracts;

interface Transport
{
    /**
     * Connect to the server.
     */
    public function connect(): void;

    /**
     * Disconnect from the server.
     */
    public function disconnect(): void;

    /**
     * Send a protocol message.
     *
     * @param array<string, string> $headers
     */
    public function send(string $message, array $headers = []): void;

    /**
     * Receive the next protocol message.
     */
    public function receive(): string;

    /**
     * Set the transport timeout in seconds.
     */
    public function setTimeoutSeconds(float $seconds): void;

    /**
     * Get the configuration needed to recreate this transport.
     *
     * @return array<string, mixed>
     */
    public function recipe(): array;
}
