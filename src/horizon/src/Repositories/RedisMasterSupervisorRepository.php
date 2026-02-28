<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Repositories;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Redis\RedisFactory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Arr;
use stdClass;

class RedisMasterSupervisorRepository implements MasterSupervisorRepository
{
    /**
     * Create a new repository instance.
     */
    public function __construct(
        public RedisFactory $redis
    ) {
    }

    /**
     * Get the names of all the master supervisors currently running.
     */
    public function names(): array
    {
        return $this->connection()->zRevRangeByScore(
            'masters',
            '+inf',
            (string) CarbonImmutable::now()->subSeconds(14)->getTimestamp()
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
     * Get information on a master supervisor by name.
     */
    public function find(string $name): ?stdClass
    {
        return Arr::get($this->get([$name]), 0);
    }

    /**
     * Get information on the given master supervisors.
     */
    public function get(array $names): array
    {
        $records = $this->connection()->pipeline(function ($pipe) use ($names) {
            foreach ($names as $name) {
                $pipe->hmget('master:' . $name, ['name', 'pid', 'status', 'supervisors', 'environment']);
            }
        });

        return collect($records)->map(function ($record) {
            return $record['name']
                ? (object) array_merge($record, ['supervisors' => json_decode($record['supervisors'], true)])
                : null;
        })->filter()->all();
    }

    /**
     * Update the information about the given master supervisor.
     */
    public function update(MasterSupervisor $master): void
    {
        /** @phpstan-ignore-next-line */
        $supervisors = $master->supervisors->map->name->all();

        $this->connection()->pipeline(function ($pipe) use ($master, $supervisors) {
            $pipe->hmset(
                'master:' . $master->name,
                [
                    'name' => $master->name,
                    'environment' => $master->environment,
                    'pid' => $master->pid(),
                    'status' => $master->working ? 'running' : 'paused',
                    'supervisors' => json_encode($supervisors),
                ]
            );

            $pipe->zadd(
                'masters',
                CarbonImmutable::now()->getTimestamp(),
                $master->name
            );

            $pipe->expire('master:' . $master->name, 15);
        });
    }

    /**
     * Remove the master supervisor information from storage.
     */
    public function forget(string $name): void
    {
        if (! $master = $this->find($name)) {
            return;
        }

        app(SupervisorRepository::class)->forget(
            $master->supervisors
        );

        $this->connection()->del('master:' . $name);

        $this->connection()->zrem('masters', $name);
    }

    /**
     * Remove expired master supervisors from storage.
     */
    public function flushExpired(): void
    {
        $this->connection()->zRemRangeByScore(
            'masters',
            '-inf',
            (string) CarbonImmutable::now()->subSeconds(14)->getTimestamp()
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
