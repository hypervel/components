<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess\Listeners;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\ServerProcess\ProcessInterface;
use Hypervel\Event\Contracts\ListenerInterface;
use Hypervel\Framework\Events\BeforeMainServerStart;
use Hypervel\ServerProcess\ProcessManager;

class BootProcessListener implements ListenerInterface
{
    public function __construct(
        private Container $container,
        private Repository $config,
    ) {
    }

    /**
     * Get the events the listener should handle.
     *
     * @return string[]
     */
    public function listen(): array
    {
        return [
            BeforeMainServerStart::class,
        ];
    }

    /**
     * Boot all registered server processes and bind them to the server.
     */
    public function process(object $event): void
    {
        /** @var BeforeMainServerStart $event */
        $server = $event->server;
        $serverConfig = $event->serverConfig;

        $serverProcesses = $serverConfig['processes'] ?? [];
        $configProcesses = $this->config->get('processes', []);

        ProcessManager::setRunning(true);

        // @TODO Add annotation-based process discovery once the DI annotation system is ported.
        $processes = array_merge($serverProcesses, $configProcesses, ProcessManager::all());
        foreach ($processes as $process) {
            if (is_string($process)) {
                $instance = $this->container->make($process);
            } else {
                $instance = $process;
            }
            if ($instance instanceof ProcessInterface) {
                $instance->isEnable($server) && $instance->bind($server);
            }
        }
    }
}
