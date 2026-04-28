<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Events;

class BeforeServerRestart
{
    public function __construct(public readonly string $pid)
    {
    }
}
