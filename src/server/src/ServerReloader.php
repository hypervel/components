<?php

declare(strict_types=1);

namespace Hypervel\Server;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Server\Exceptions\InvalidArgumentException;
use Hypervel\Server\Exceptions\ServerException;

class ServerReloader
{
    /**
     * Create a new server reloader.
     */
    public function __construct(
        protected Repository $config,
        protected Filesystem $filesystem,
    ) {
    }

    /**
     * Reload the server's event and configured task workers.
     *
     * @throws FileNotFoundException
     * @throws InvalidArgumentException
     * @throws ServerException
     */
    public function reload(): void
    {
        $pidFile = $this->config->string('server.settings.pid_file');
        $contents = $this->filesystem->get($pidFile);
        $pid = filter_var(trim($contents), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($pid === false) {
            throw new InvalidArgumentException(
                "The server PID file [{$pidFile}] does not contain a valid process ID."
            );
        }

        if (! $this->signalProcess($pid, SIGUSR1)) {
            throw new ServerException('Unable to send [SIGUSR1] to reload event workers.');
        }

        if ($this->config->integer('server.settings.task_worker_num') > 0
            && ! $this->signalProcess($pid, SIGUSR2)) {
            throw new ServerException('Unable to send [SIGUSR2] to reload task workers.');
        }
    }

    /**
     * Send a signal to the server process.
     */
    protected function signalProcess(int $pid, int $signal): bool
    {
        return posix_kill($pid, $signal);
    }
}
