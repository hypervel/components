<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

use Hypervel\Reverb\Contracts\WebSocketConnection;
use Hypervel\WebSocketServer\Sender;

class Connection implements WebSocketConnection
{
    /**
     * Create a new connection instance.
     */
    public function __construct(
        protected Sender $sender,
        protected int $fd,
    ) {
    }

    /**
     * Get the raw socket connection identifier.
     */
    public function id(): int
    {
        return $this->fd;
    }

    /**
     * Send a message to the connection.
     */
    public function send(mixed $message): void
    {
        $this->sender->push($this->fd, (string) $message);
    }

    /**
     * Send a control frame to the connection.
     */
    public function control(int $opcode): void
    {
        $this->sender->push($this->fd, '', $opcode);
    }

    /**
     * Close the connection.
     */
    public function close(mixed $message = null): void
    {
        if ($message !== null) {
            $this->send($message);
        }

        $this->sender->disconnect($this->fd);
    }
}
