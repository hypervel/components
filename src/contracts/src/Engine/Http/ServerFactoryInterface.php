<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http;

interface ServerFactoryInterface
{
    public function make(string $name, int $port = 0): ServerInterface;
}
