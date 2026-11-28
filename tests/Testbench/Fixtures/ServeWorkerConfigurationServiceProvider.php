<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Fixtures;

use Hypervel\Support\ServiceProvider;

class ServeWorkerConfigurationServiceProvider extends ServiceProvider
{
    /**
     * Expose the boot-time and request-time configuration in the serving worker.
     */
    public function boot(): void
    {
        $config = $this->app->make('config');
        // With one worker, a different PID identifies a replacement.
        $config->set('server.settings.worker_num', 1);
        $snapshot = $config->string('recycling.value');

        $this->app->make('router')->get('/worker-config', static fn (): array => [
            'pid' => getmypid(),
            'snapshot' => $snapshot,
            'current' => $config->string('recycling.value'),
        ]);
    }
}
