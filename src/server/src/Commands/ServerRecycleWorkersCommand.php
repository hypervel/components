<?php

declare(strict_types=1);

namespace Hypervel\Server\Commands;

use Hypervel\Console\Command;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'server:recycle-workers')]
class ServerRecycleWorkersCommand extends Command
{
    protected ?string $signature = 'server:recycle-workers';

    protected string $description = 'Gracefully replace the server\'s event and task workers.';

    public function __construct(
        protected Repository $config,
        protected Filesystem $filesystem
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $file = $this->config->string('server.settings.pid_file');

        try {
            $contents = $this->filesystem->get($file);
        } catch (FileNotFoundException) {
            $this->components->error("Unable to read the server PID file [{$file}].");

            return self::FAILURE;
        }

        $pid = filter_var(trim($contents), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($pid === false) {
            $this->components->error("The server PID file [{$file}] does not contain a valid process ID.");

            return self::FAILURE;
        }

        // SIGUSR1 replaces both event and task workers.
        if (! $this->signalProcess($pid, SIGUSR1)) {
            $this->components->error('Unable to signal the server to recycle its workers.');

            return self::FAILURE;
        }

        $this->components->info('Worker recycling requested.');

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
