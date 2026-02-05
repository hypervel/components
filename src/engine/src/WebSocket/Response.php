<?php

declare(strict_types=1);

namespace Hypervel\Engine\WebSocket;

use Hypervel\Contracts\Engine\WebSocket\FrameInterface;
use Hypervel\Contracts\Engine\WebSocket\ResponseInterface;
use Hypervel\Engine\Exception\InvalidArgumentException;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;
use Swoole\WebSocket\Frame as SwooleFrame;
use Swoole\WebSocket\Server;

use function Hypervel\Engine\swoole_get_flags_from_frame;

class Response implements ResponseInterface
{
    protected int $fd = 0;

    /**
     * Create a new WebSocket response instance.
     */
    public function __construct(protected mixed $connection)
    {
    }

    /**
     * Push a frame to the WebSocket connection.
     */
    public function push(FrameInterface $frame): bool
    {
        $data = (string) $frame->getPayloadData();
        $flags = swoole_get_flags_from_frame($frame);

        if ($this->connection instanceof SwooleResponse) {
            $this->connection->push($data, $frame->getOpcode(), $flags);
            return true;
        }

        if ($this->connection instanceof Server) {
            $this->connection->push($this->fd, $data, $frame->getOpcode(), $flags);
            return true;
        }

        throw new InvalidArgumentException('The websocket connection is invalid.');
    }

    /**
     * Initialize the file descriptor from a frame or request.
     */
    public function init(mixed $frame): static
    {
        switch (true) {
            case is_int($frame):
                $this->fd = $frame;
                break;
            case $frame instanceof Request || $frame instanceof SwooleFrame:
                $this->fd = $frame->fd;
                break;
        }

        return $this;
    }

    /**
     * Get the file descriptor.
     */
    public function getFd(): int
    {
        return $this->fd;
    }

    /**
     * Close the WebSocket connection.
     */
    public function close(): bool
    {
        if ($this->connection instanceof SwooleResponse) {
            return $this->connection->close();
        }

        if ($this->connection instanceof Server) {
            return $this->connection->disconnect($this->fd);
        }

        return false;
    }
}
