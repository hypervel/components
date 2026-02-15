<?php

declare(strict_types=1);

namespace Hypervel\Server\Listener;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Process\Event\BeforeProcessHandle;
use Hypervel\Event\Contracts\ListenerInterface;
use Hypervel\Framework\Events\AfterWorkerStart;
use Hypervel\Framework\Events\OnManagerStart;
use Hypervel\Framework\Events\OnStart;
use Psr\Container\ContainerInterface;

class InitProcessTitleListener implements ListenerInterface
{
    protected string $name = '';

    protected string $dot = '.';

    public function __construct(ContainerInterface $container)
    {
        if ($container->has(ConfigInterface::class)) {
            if ($name = $container->make(ConfigInterface::class)->get('app_name')) {
                $this->name = $name;
            }
        }
    }

    /**
     * Get the events the listener should handle.
     */
    public function listen(): array
    {
        return [
            OnStart::class,
            OnManagerStart::class,
            AfterWorkerStart::class,
            BeforeProcessHandle::class,
        ];
    }

    /**
     * Set the process title based on the event type.
     */
    public function process(object $event): void
    {
        $array = [];
        if ($this->name !== '') {
            $array[] = $this->name;
        }

        if ($event instanceof OnStart) {
            $array[] = 'Master';
        } elseif ($event instanceof OnManagerStart) {
            $array[] = 'Manager';
        } elseif ($event instanceof AfterWorkerStart) {
            if ($event->server->taskworker) {
                $array[] = 'TaskWorker';
            } else {
                $array[] = 'Worker';
            }
            $array[] = $event->workerId;
        } elseif ($event instanceof BeforeProcessHandle) {
            $array[] = $event->process->name;
            $array[] = $event->index;
        }

        if ($title = implode($this->dot, $array)) {
            $this->setTitle($title);
        }
    }

    /**
     * Set the CLI process title.
     */
    protected function setTitle(string $title): void
    {
        if ($this->isSupportedOS()) {
            @cli_set_process_title($title);
        }
    }

    /**
     * Determine if the current OS supports setting process titles.
     */
    protected function isSupportedOS(): bool
    {
        return PHP_OS !== 'Darwin';
    }
}
