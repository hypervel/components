<?php

declare(strict_types=1);

namespace Hypervel\Server\Listeners;

use Hypervel\Contracts\Container\Container;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\OnManagerStart;
use Hypervel\Core\Events\OnStart;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;

class InitProcessTitleListener
{
    protected string $name = '';

    protected string $dot = '.';

    public function __construct(Container $container)
    {
        if ($container->has('config')) {
            if ($name = $container->make('config')->get('app.name')) {
                $this->name = $name;
            }
        }
    }

    /**
     * Set the process title based on the event type.
     */
    public function handle(AfterWorkerStart|OnStart|OnManagerStart|BeforeProcessHandle $event): void
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
