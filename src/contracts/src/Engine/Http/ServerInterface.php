<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http;

interface ServerInterface
{
    public function handle(callable $callable): static;

    public function start(): void;

    public function close(): bool;
}
