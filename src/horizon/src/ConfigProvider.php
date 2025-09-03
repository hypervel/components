<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
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
            ],
            'commands' => [
                Console\ClearCommand::class,
                Console\ClearMetricsCommand::class,
                Console\ContinueCommand::class,
                Console\ContinueSupervisorCommand::class,
                Console\ForgetFailedCommand::class,
                Console\HorizonCommand::class,
                Console\InstallCommand::class,
                Console\ListCommand::class,
                Console\PauseCommand::class,
                Console\PauseSupervisorCommand::class,
                Console\PurgeCommand::class,
                Console\SupervisorCommand::class,
                Console\SupervisorStatusCommand::class,
                Console\TerminateCommand::class,
                Console\TimeoutCommand::class,
                Console\WorkCommand::class,
                Console\SnapshotCommand::class,
                Console\StatusCommand::class,
                Console\SupervisorsCommand::class,
            ],
            'publish' => [
                [
                    'id' => 'provider',
                    'description' => 'The provider for horizon.',
                    'source' => __DIR__ . '/../stubs/HorizonServiceProvider.stub',
                    'destination' => BASE_PATH . '/app/Providers/HorizonServiceProvider.php',
                ],
                [
                    'id' => 'config',
                    'description' => 'The config for horizon.',
                    'source' => __DIR__ . '/../config/horizon.php',
                    'destination' => BASE_PATH . '/config/horizon.php',
                ],
            ],
        ];
    }
}
