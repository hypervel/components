<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures;

use Hypervel\Core\Bootstrap\TaskCallback;
use Hypervel\Server\Event;
use Hypervel\Support\ServiceProvider;
use Swoole\Server;

class ServeWorkerConfigurationServiceProvider extends ServiceProvider
{
    /**
     * Expose the boot-time and request-time configuration in the serving worker.
     */
    public function boot(): void
    {
        $config = $this->app->make('config');
        // With one worker per pool, task worker 1 follows event worker 0.
        $config->set([
            'server.settings.worker_num' => 1,
            'server.settings.task_worker_num' => 1,
            'server.settings.task_enable_coroutine' => true,
            'server.callbacks.' . Event::ON_TASK => [TaskCallback::class, 'onTask'],
        ]);
        $snapshot = $config->string('recycling.value');

        $this->app->make('router')->get('/worker-config', static fn (Server $server): array => [
            'pid' => getmypid(),
            'task_pid' => $server->getWorkerPid(1),
            'snapshot' => $snapshot,
            'current' => $config->string('recycling.value'),
        ]);
    }
}
