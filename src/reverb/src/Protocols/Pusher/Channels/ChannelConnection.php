<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Channels;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Support\Arr;

/**
 * @method string id()
 * @method string identifier()
 * @method Application app()
 * @method ?string origin()
 * @method void ping()
 * @method void pong()
 * @method ?int lastSeenAt()
 * @method static setLastSeenAt(int $time)
 * @method static touch()
 * @method void disconnect()
 * @method bool isActive()
 * @method bool isInactive()
 * @method bool isStale()
 * @method bool usesControlFrames()
 * @method static setUsesControlFrames(bool $usesControlFrames = true)
 * @method void terminate()
 * @method void control(int $opcode)
 */
class ChannelConnection
{
    /**
     * Create a new channel connection instance.
     */
    public function __construct(
        protected Connection $connection,
        protected array $data = [],
    ) {
    }

    /**
     * Get the underlying connection.
     */
    public function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * Get the connection data.
     */
    public function data(?string $key = null): mixed
    {
        return $key ? Arr::get($this->data, $key) : $this->data;
    }

    /**
     * Send a message to the connection.
     */
    public function send(string $message): void
    {
        $this->connection->send($message);
    }

    /**
     * Proxy the given method to the underlying connection.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection->{$method}(...$parameters);
    }
}
