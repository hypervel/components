<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel;

use Hypervel\Routing\Router;

/**
 * Isolated router for Reverb's HTTP API and WebSocket routes.
 *
 * Exists as a subclass for container type distinction — the app uses
 * the 'router' binding while Reverb resolves ReverbRouter::class.
 * This ensures Reverb routes are invisible to the app and vice versa.
 */
class ReverbRouter extends Router
{
}
