<?php

declare(strict_types=1);

namespace Hypervel\JWT\Contracts;

interface StorageContract
{
    public function add(string $key, mixed $value, int $minutes): void;

    public function forever(string $key, mixed $value): void;

    public function get(string $key): mixed;

    public function destroy(string $key): bool;

    public function flush(): void;
}
