<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Repositories;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\Supervisor;
use Hypervel\Redis\RedisFactory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Arr;
use stdClass;

class RedisSupervisorRepository implements SupervisorRepository
{
    /**
     * Create a new repository instance.
     */
    public function __construct(
        public RedisFactory $redis
    ) {
    }

    /**
     * Get the names of all the supervisors currently running.
     */
    public function names(): array
    {
        return $this->connection()->zRevRangeByScore(
            'supervisors',
            '+inf',
            CarbonImmutable::now()->subSeconds(29)->getTimestamp()
        );
    }

    /**
     * Get information on all of the supervisors.
     */
    public function all(): array
    {
        return $this->get($this->names());
    }

    /**
     * Get information on a supervisor by name.
     */
    public function find(string $name): ?stdClass
    {
        return Arr::get($this->get([$name]), 0);
    }

    /**
     * Get information on the given supervisors.
     */
    public function get(array $names): array
    {
        /** @var array<int, array{name: string, master: string, pid: int, status: string, processes: string, options: string}> $records */
        $records = $this->connection()->pipeline(function ($pipe) use ($names) {
            foreach ($names as $name) {
                $pipe->hmget('supervisor:' . $name, ['name', 'master', 'pid', 'status', 'processes', 'options']);
            }
        });

        return collect($records)->filter()->map(function ($record) {
            return $record['name']
                ? (object) array_merge($record, [
                    'processes' => json_decode($record['processes'], true),
                    'options' => json_decode($record['options'], true),
                ])
                : null;
        })->filter()->all();
    }

    /**
     * Get the longest active timeout setting for a supervisor.
     */
    public function longestActiveTimeout(): int
    {
        return collect($this->all())->max(function ($supervisor) {
            return $supervisor->options['timeout'];
        }) ?: 0;
    }

    /**
     * Update the information about the given supervisor process.
     */
    public function update(Supervisor $supervisor): void
    {
        $processes = $supervisor->processPools->mapWithKeys(function ($pool) use ($supervisor) {
            return [$supervisor->options->connection . ':' . $pool->queue() => count($pool->processes())];
        })->toJson();

        $this->connection()->pipeline(function ($pipe) use ($supervisor, $processes) {
            $pipe->hmset(
                'supervisor:' . $supervisor->name,
                [
                    'name' => $supervisor->name,
                    'master' => implode(':', explode(':', $supervisor->name, -1)),
                    'pid' => $supervisor->pid(),
                    'status' => $supervisor->working ? 'running' : 'paused',
                    'processes' => $processes,
                    'options' => $supervisor->options->toJson(),
                ]
            );

            $pipe->zadd(
                'supervisors',
                CarbonImmutable::now()->getTimestamp(),
                $supervisor->name
            );

            $pipe->expire('supervisor:' . $supervisor->name, 30);
        });
    }

    /**
     * Remove the supervisor information from storage.
     */
    public function forget(array|string $names): void
    {
        $names = (array) $names;

        if (empty($names)) {
            return;
        }

        $this->connection()->del(...collect($names)->map(function ($name) {
            return 'supervisor:' . $name;
        })->all());

        $this->connection()->zrem('supervisors', ...$names);
    }

    /**
     * Remove expired supervisors from storage.
     */
    public function flushExpired(): void
    {
        $this->connection()->zRemRangeByScore(
            'supervisors',
            '-inf',
            CarbonImmutable::now()->subSeconds(14)->getTimestamp()
        );
    }

    /**
     * Get the Redis connection instance.
     */
    protected function connection(): RedisProxy
    {
        return $this->redis->get('horizon');
    }
}
