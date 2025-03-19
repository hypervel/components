<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Queue\Console\ClearCommand;
use Hypervel\Queue\Console\FlushFailedCommand;
use Hypervel\Queue\Console\ForgetFailedCommand;
use Hypervel\Queue\Console\ListenCommand;
use Hypervel\Queue\Console\ListFailedCommand;
use Hypervel\Queue\Console\MonitorCommand;
use Hypervel\Queue\Console\PruneBatchesCommand;
use Hypervel\Queue\Console\PruneFailedJobsCommand;
use Hypervel\Queue\Console\RestartCommand;
use Hypervel\Queue\Console\RetryBatchCommand;
use Hypervel\Queue\Console\RetryCommand;
use Hypervel\Queue\Console\WorkCommand;
use Hypervel\Queue\Contracts\Factory as FactoryContract;
use Hypervel\Queue\Contracts\Queue;
use Hypervel\Queue\Failed\FailedJobProviderFactory;
use Hypervel\Queue\Failed\FailedJobProviderInterface;
use Laravel\SerializableClosure\SerializableClosure;
use Psr\Container\ContainerInterface;

class ConfigProvider
{
    public function __invoke(): array
    {
        $this->configureSerializableClosureUses();

        return [
            'dependencies' => [
                FactoryContract::class => QueueManager::class,
                Queue::class => fn (ContainerInterface $container) => $container->get(FactoryContract::class)->connection(),
                FailedJobProviderInterface::class => FailedJobProviderFactory::class,
                Listener::class => fn (ContainerInterface $container) => new Listener($this->getBasePath($container)),
                Worker::class => WorkerFactory::class,
            ],
            'commands' => [
                WorkCommand::class,
                ClearCommand::class,
                FlushFailedCommand::class,
                ForgetFailedCommand::class,
                ListFailedCommand::class,
                ListenCommand::class,
                MonitorCommand::class,
                PruneBatchesCommand::class,
                PruneFailedJobsCommand::class,
                RestartCommand::class,
                RetryBatchCommand::class,
                RetryCommand::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration file of queue.',
                    'source' => __DIR__ . '/../publish/queue.php',
                    'destination' => BASE_PATH . '/config/autoload/queue.php',
                ],
            ],
        ];
    }

    /**
     * Configure serializable closures uses.
     */
    protected function configureSerializableClosureUses(): void
    {
        SerializableClosure::transformUseVariablesUsing(function ($data) {
            foreach ($data as $key => $value) {
                /* @phpstan-ignore-next-line */
                $data[$key] = $this->getSerializedPropertyValue($value);
            }

            return $data;
        });

        SerializableClosure::resolveUseVariablesUsing(function ($data) {
            foreach ($data as $key => $value) {
                /* @phpstan-ignore-next-line */
                $data[$key] = $this->getRestoredPropertyValue($value);
            }

            return $data;
        });
    }

    protected function getBasePath(ContainerInterface $container): string
    {
        return method_exists($container, 'basePath')
            ? $container->basePath()
            : BASE_PATH;
    }
}
