<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Contracts;

interface StorageContract
{
    public function add(string $key, mixed $value, int $minutes): bool;

    public function forever(string $key, mixed $value): bool;

    public function get(string $key): mixed;

    public function destroy(string $key): bool;

    public function flush(): bool;
}
