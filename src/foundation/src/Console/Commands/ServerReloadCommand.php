<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console\Commands;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Support\Filesystem\FileNotFoundException;
use Hyperf\Support\Filesystem\Filesystem;
use Hypervel\Console\Command;
use Psr\Container\ContainerInterface;
use Throwable;

class ServerReloadCommand extends Command
{
    protected ?string $signature = 'server:reload';

    protected string $description = 'Reload all workers gracefully.';

    public function __construct(
        protected ContainerInterface $container,
        protected ConfigInterface $config,
        protected Filesystem $filesystem
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $file = $this->config->get('server.settings.pid_file');
        if (empty($file)) {
            throw new FileNotFoundException('The config of pid_file is not found.');
        }

        if (! $this->filesystem->exists($file)) {
            $this->warn("pid_file doesn't exist.");
            return 0;
        }

        $hasTaskWorkers = (bool) $this->config->get('server.settings.task_worker_num', 0);
        $pid = $this->filesystem->get($file);
        try {
            $this->info('Reloading workers...');
            if (posix_kill((int) $pid, 0)) {
                posix_kill((int) $pid, SIGUSR1);
            } else {
                $this->warn('No active workers.');
            }

            if (! $hasTaskWorkers) {
                return 0;
            }

            $this->info('Reloading task workers...');
            if (posix_kill((int) $pid, 0)) {
                posix_kill((int) $pid, SIGUSR2);
            } else {
                $this->warn('No active task workers.');
            }

            $this->info('Done.');
        } catch (Throwable $e) {
            $this->error('Reload failed.');
            return 1;
        }

        return 0;
    }
}
