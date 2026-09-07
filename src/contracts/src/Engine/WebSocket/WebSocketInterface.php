<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\WebSocket;

interface WebSocketInterface
{
    public const string ON_MESSAGE = 'message';

    public const string ON_CLOSE = 'close';

    /**
     * Register an event callback.
     */
    public function on(string $event, callable $callback): void;

    /**
     * Start the WebSocket server.
     */
    public function start(): void;
}
