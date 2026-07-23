<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

use Hypervel\Foundation\Http\WebSocketKernel;
use Hypervel\Routing\Router;

/**
 * WebSocket handshake handler for the Reverb server port.
 *
 * Extends the foundation WebSocketKernel to inherit proper exception
 * handling, and overrides getRouter() to use the isolated ReverbRouter
 * for route matching during handshake.
 */
class WebSocketServer extends WebSocketKernel
{
    /**
     * Get the router instance for WebSocket handshake route matching.
     */
    protected function getRouter(): Router
    {
        return $this->container->make(ReverbRouter::class);
    }
}
