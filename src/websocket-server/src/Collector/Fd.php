<?php

declare(strict_types=1);

namespace Hypervel\WebSocketServer\Collector;

class Fd
{
    public function __construct(public int $fd, public string $class)
    {
    }
}
