<?php

declare(strict_types=1);

namespace Hypervel\Server\Commands;

use Hypervel\Console\Command;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'server:reload')]
class ServerReloadCommand extends Command
{
    protected ?string $signature = 'server:reload';

    protected string $description = 'Reload all workers gracefully.';

    public function __construct(
        protected Repository $config,
        protected Filesystem $filesystem
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $file = $this->config->string('server.settings.pid_file');
        $hasTaskWorkers = $this->config->integer('server.settings.task_worker_num') > 0;

        try {
            $contents = $this->filesystem->get($file);
        } catch (FileNotFoundException) {
            $this->warn("Unable to read the server PID file [{$file}].");

            return self::FAILURE;
        }

        $pid = filter_var(trim($contents), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($pid === false) {
            $this->error("The server PID file [{$file}] does not contain a valid process ID.");

            return self::FAILURE;
        }

        $this->info('Reloading workers...');

        if (! $this->signalProcess($pid, SIGUSR1)) {
            $this->warn('Unable to reload workers.');

            return self::FAILURE;
        }

        if ($hasTaskWorkers) {
            $this->info('Reloading task workers...');

            if (! $this->signalProcess($pid, SIGUSR2)) {
                $this->warn('Unable to reload task workers.');

                return self::FAILURE;
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * Send a signal to the server process.
     */
    protected function signalProcess(int $pid, int $signal): bool
    {
        return posix_kill($pid, $signal);
    }
}
