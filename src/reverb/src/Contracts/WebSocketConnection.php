<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Contracts;

interface WebSocketConnection
{
    /**
     * Get the raw socket connection identifier.
     */
    public function id(): int|string;

    /**
     * Send a message to the connection.
     */
    public function send(mixed $message): void;

    /**
     * Send a control frame to the connection.
     */
    public function control(int $opcode): void;

    /**
     * Close the connection.
     */
    public function close(mixed $message = null, ?int $code = null, ?string $reason = null): void;
}
