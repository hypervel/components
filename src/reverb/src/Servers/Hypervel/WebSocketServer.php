<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

use Hypervel\Contracts\Http\Kernel as KernelContract;
use Hypervel\Foundation\Http\WebsocketKernel;
use Hypervel\Routing\Router;
use Hypervel\WebSocketServer\CoreMiddleware;

/**
 * WebSocket handshake handler for the Reverb server port.
 *
 * Extends the foundation WebsocketKernel to inherit proper exception
 * handling, and overrides getRouter() to use the isolated ReverbRouter
 * for route matching during handshake.
 */
class WebSocketServer extends WebsocketKernel
{
    /**
     * Bootstrap the application and compile the Reverb router.
     *
     * Overrides the parent to compile the isolated ReverbRouter instead
     * of the global app Router. The kernel bootstrap is idempotent.
     */
    public function initCoreMiddleware(string $serverName): void
    {
        $this->serverName = $serverName;

        $this->kernel = $this->container->make(KernelContract::class);
        $this->kernel->bootstrap();

        $this->container->make(ReverbRouter::class)->compileAndWarm();

        $this->coreMiddleware = new CoreMiddleware($this->container);

        $this->initOption();
    }

    /**
     * Get the router instance for WebSocket handshake route matching.
     */
    protected function getRouter(): Router
    {
        return $this->container->make(ReverbRouter::class);
    }
}
