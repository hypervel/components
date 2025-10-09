<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

trait ServiceBindings
{
    /**
     * All of the service bindings for Horizon.
     */
    public array $serviceBindings = [
        // General services...
        Contracts\HorizonCommandQueue::class => RedisHorizonCommandQueue::class,

        // Repository services...
        Contracts\JobRepository::class => Repositories\RedisJobRepository::class,
        Contracts\MasterSupervisorRepository::class => Repositories\RedisMasterSupervisorRepository::class,
        Contracts\MetricsRepository::class => Repositories\RedisMetricsRepository::class,
        Contracts\ProcessRepository::class => Repositories\RedisProcessRepository::class,
        Contracts\SupervisorRepository::class => Repositories\RedisSupervisorRepository::class,
        Contracts\TagRepository::class => Repositories\RedisTagRepository::class,
        Contracts\WorkloadRepository::class => Repositories\RedisWorkloadRepository::class,

        // Notifications...
        Contracts\LongWaitDetectedNotification::class => Notifications\LongWaitDetected::class,
    ];
}
