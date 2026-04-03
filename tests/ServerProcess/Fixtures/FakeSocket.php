<?php

declare(strict_types=1);

namespace Hypervel\Tests\ServerProcess\Fixtures;

use Swoole\Coroutine\Socket;

/**
 * Fake socket for testing AbstractProcess::listen().
 *
 * Replays a sequence of recv() results, then returns timeout forever.
 */
class FakeSocket extends Socket
{
    private int $callIndex = 0;

    /**
     * @param list<array{0: false|string, 1: int}> $results each entry is [returnValue, errCode]
     */
    public function __construct(
        private array $results = [],
    ) {
        // Create a dummy socket — we never use the underlying fd.
        parent::__construct(AF_INET, SOCK_STREAM);
    }

    /**
     * Return the next result in the sequence, then timeout forever.
     */
    public function recv(int $length = 65535, float $timeout = -1): string|false
    {
        if ($this->callIndex < count($this->results)) {
            [$value, $errCode] = $this->results[$this->callIndex];
            $this->errCode = $errCode;
            ++$this->callIndex;
            return $value;
        }

        // Default: timeout (normal idle behavior).
        $this->errCode = SOCKET_ETIMEDOUT;
        return false;
    }

    /**
     * Get the number of recv() calls made.
     */
    public function getCallCount(): int
    {
        return $this->callIndex;
    }
}
