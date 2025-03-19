<?php

declare(strict_types=1);

namespace Hypervel\Session;

use SessionHandlerInterface;

class NullSessionHandler implements SessionHandlerInterface
{
    public function open(string $savePath, string $sessionName): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $sessionId): string
    {
        return '';
    }

    public function write($sessionId, $data): bool
    {
        return true;
    }

    public function destroy($sessionId): bool
    {
        return true;
    }

    public function gc(int $lifetime): int
    {
        return 0;
    }
}
