<?php

declare(strict_types=1);

namespace Hypervel\Reverb;

use Hypervel\Reverb\Concerns\GeneratesIdentifiers;
use Hypervel\Reverb\Contracts\Connection as ConnectionContract;
use Hypervel\Reverb\Events\MessageSent;

class Connection extends ConnectionContract
{
    use GeneratesIdentifiers;

    /**
     * The normalized socket ID.
     */
    protected ?string $id = null;

    /**
     * Get the raw socket connection identifier.
     */
    public function identifier(): string
    {
        return (string) $this->connection->id();
    }

    /**
     * Get the normalized socket ID.
     */
    public function id(): string
    {
        if (! $this->id) {
            $this->id = $this->generateId();
        }

        return $this->id;
    }

    /**
     * Send a message to the connection.
     */
    public function send(string $message): void
    {
        $this->connection->send($message);

        if (app('events')->hasListeners(MessageSent::class)) {
            MessageSent::dispatch($this, $message);
        }
    }

    /**
     * Send a control frame to the connection.
     */
    public function control(int $opcode = WEBSOCKET_OPCODE_PING): void
    {
        $this->connection->control($opcode);
    }

    /**
     * Terminate a connection.
     */
    public function terminate(?int $code = null, ?string $reason = null): void
    {
        $this->connection->close(code: $code, reason: $reason);
    }
}
