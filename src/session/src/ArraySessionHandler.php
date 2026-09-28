<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Support\InteractsWithTime;
use RuntimeException;
use SessionHandlerInterface;

class ArraySessionHandler implements SessionHandlerInterface
{
    use InteractsWithTime;

    /**
     * The array of stored values.
     */
    protected array $storage = [];

    /**
     * Create a new array driven handler instance.
     *
     * @param int $minutes the number of minutes the session should be valid
     */
    public function __construct(
        protected int $minutes
    ) {
    }

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
        return isset($this->storage[$id]);
    }

    public function read(string $sessionId): false|string
    {
        if (! isset($this->storage[$sessionId])) {
            return '';
        }

        $session = $this->storage[$sessionId];

        $expiration = $this->calculateExpiration($this->minutes * 60);

        if (isset($session['time']) && $session['time'] >= $expiration) {
            return $session['data'];
        }

        return '';
    }

    public function write(string $sessionId, string $data): bool
    {
        $this->storage[$sessionId] = [
            'data' => $data,
            'time' => $this->currentTime(),
        ];

        return true;
    }

    public function destroy(string $sessionId): bool
    {
        if (isset($this->storage[$sessionId])) {
            unset($this->storage[$sessionId]);
        }

        return true;
    }

    public function gc(int $lifetime): int
    {
        $expiration = $this->calculateExpiration($lifetime);

        $deletedSessions = 0;

        foreach ($this->storage as $sessionId => $session) {
            if ($session['time'] < $expiration) {
                unset($this->storage[$sessionId]);
                ++$deletedSessions;
            }
        }

        return $deletedSessions;
    }

    /**
     * Get the expiration time of the session.
     */
    protected function calculateExpiration(int $seconds): int
    {
        return $this->currentTime() - $seconds;
    }
}
