<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess\Listeners;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\ServerProcess\ProcessInterface;
use Hypervel\Core\Events\BeforeMainServerStart;
use Hypervel\ServerProcess\ProcessManager;

class BootProcessListener
{
    public function __construct(
        private Container $container,
        private Repository $config,
    ) {
    }

    /**
     * Boot all registered server processes and bind them to the server.
     */
    public function handle(BeforeMainServerStart $event): void
    {
        $server = $event->server;
        $serverConfig = $event->serverConfig;

        $serverProcesses = $serverConfig['processes'] ?? [];
        $configProcesses = $this->config->array('processes', []);

        ProcessManager::setRunning(true);

        $processes = array_merge($serverProcesses, $configProcesses, ProcessManager::all());
        $seenClasses = [];
        $seen = [];
        foreach ($processes as $process) {
            if (is_string($process)) {
                if (isset($seenClasses[$process])) {
                    continue;
                }

                $seenClasses[$process] = true;
                $instance = $this->container->make($process);
            } else {
                $instance = $process;
            }

            if (! $instance instanceof ProcessInterface) {
                continue;
            }

            $id = spl_object_id($instance);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $instance->isEnable($server) && $instance->bind($server);
        }
    }
}
