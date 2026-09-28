<?php

declare(strict_types=1);

namespace Hypervel\Session;

use RuntimeException;
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

    /**
     * Create a new session ID.
     */
    public function create_sid(): string
    {
        return session_create_id() ?: throw new RuntimeException('Unable to create a session ID.');
    }

    /**
     * Determine if the session ID exists.
     */
    public function validateId(string $id): bool
    {
        return true;
    }

    public function read(string $sessionId): string
    {
        return '';
    }

    public function write(string $sessionId, string $data): bool
    {
        return true;
    }

    public function destroy(string $sessionId): bool
    {
        return true;
    }

    public function gc(int $lifetime): int
    {
        return 0;
    }
}
