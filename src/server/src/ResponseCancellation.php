<?php

declare(strict_types=1);

namespace Hypervel\Server;

use Hypervel\Engine\Coroutine;

/**
 * Track only active response producers, so disconnects cannot cancel later request work.
 *
 * @internal
 */
class ResponseCancellation
{
    /** @var array<int, array<int, self>> */
    protected static array $responses = [];

    /**
     * Identify the execution producing one response on a connection.
     */
    private function __construct(
        protected int $connection,
        protected int $coroutine,
    ) {
    }

    /**
     * Register the current coroutine for the duration of response production.
     */
    public static function register(int $connection): ?self
    {
        $coroutine = Coroutine::id();

        if ($coroutine < 0) {
            return null;
        }

        return self::$responses[$connection][$coroutine] = new self($connection, $coroutine);
    }

    /**
     * Cancel every active producer on a closed connection.
     */
    public static function cancel(int $connection): void
    {
        $responses = self::$responses[$connection] ?? [];
        unset(self::$responses[$connection]);

        foreach ($responses as $response) {
            Coroutine::cancelById($response->coroutine, true);
        }
    }

    /**
     * Remove this registration without disturbing a newer response.
     */
    public function release(): void
    {
        if ((self::$responses[$this->connection][$this->coroutine] ?? null) !== $this) {
            return;
        }

        unset(self::$responses[$this->connection][$this->coroutine]);

        if (self::$responses[$this->connection] === []) {
            unset(self::$responses[$this->connection]);
        }
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$responses = [];
    }
}
