<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer;

class SenderPipeMessage
{
    public function __construct(public string $name, public array $arguments)
    {
    }
}
