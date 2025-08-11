<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Horizon\Contracts\HorizonCommandQueue;
use Hypervel\Redis\RedisConnection;

class RedisHorizonCommandQueue implements HorizonCommandQueue
{
    /**
     * Create a new command queue instance.
     *
     * @param RedisFactory $redis the Redis connection instance
     */
    public function __construct(
        public RedisFactory $redis
    ) {
    }

    /**
     * Push a command onto a given queue.
     */
    public function push(string $name, string $command, array $options = []): void
    {
        $this->connection()->rpush('commands:' . $name, json_encode([
            'command' => $command,
            'options' => $options,
        ]));
    }

    /**
     * Get the pending commands for a given queue name.
     */
    public function pending(string $name): array
    {
        $length = $this->connection()->llen('commands:' . $name);

        if ($length < 1) {
            return [];
        }

        $results = $this->connection()->pipeline(function ($pipe) use ($name, $length) {
            $pipe->lrange('commands:' . $name, 0, $length - 1);

            $pipe->ltrim('commands:' . $name, $length, -1);
        });

        return collect($results[0])->map(function ($result) {
            return (object) json_decode($result, true);
        })->all();
    }

    /**
     * Flush the command queue for a given queue name.
     */
    public function flush(string $name): void
    {
        $this->connection()->del('commands:' . $name);
    }

    /**
     * Get the Redis connection instance.
     */
    protected function connection(): RedisConnection
    {
        return $this->redis->connection('horizon');
    }
}
