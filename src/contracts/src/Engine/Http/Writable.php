<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Engine\Http;

interface Writable
{
    public function getSocket(): mixed;

    public function write(string $data): bool;

    public function end(): ?bool;
}
