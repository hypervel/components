<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client;

use Hypervel\Context\NonCopyableContext;
use Hypervel\Mcp\Client;

/**
 * Keep named clients with their owner when coroutine context is copied.
 *
 * @internal
 */
class ClientManagerState implements NonCopyableContext
{
    /** @var array<string, Client> */
    public array $clients = [];
}
