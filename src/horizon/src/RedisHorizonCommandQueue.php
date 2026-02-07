<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Hypervel\Redis\RedisFactory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Horizon\Contracts\HorizonCommandQueue;

class RedisHorizonCommandQueue implements HorizonCommandQueue
{
    /**
     * Create a new command queue instance.
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
        $this->connection()->rPush('commands:' . $name, json_encode([
            'command' => $command,
            'options' => $options,
        ]));
    }

    /**
     * Get the pending commands for a given queue name.
     */
    public function pending(string $name): array
    {
        /** @var int */
        $length = $this->connection()->lLen('commands:' . $name);

        if ($length < 1) {
            return [];
        }

        $results = $this->connection()->pipeline(function ($pipe) use ($name, $length) {
            $pipe->lRange('commands:' . $name, 0, $length - 1);

            $pipe->lTrim('commands:' . $name, $length, -1);
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
    protected function connection(): RedisProxy
    {
        return $this->redis->get('horizon');
    }
}
