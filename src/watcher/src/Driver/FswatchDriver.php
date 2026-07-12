<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use InvalidArgumentException;
use RuntimeException;

class FswatchDriver extends AbstractDriver
{
    protected mixed $process = null;

    /** @var array<int, resource> */
    protected array $pipes = [];

    public function __construct(protected Option $option)
    {
        parent::__construct($option);
        $result = $this->exec('which fswatch');
        if (empty($result['output'])) {
            throw new InvalidArgumentException('fswatch not exists. You can `brew install fswatch` to install it.');
        }
    }

    /**
     * Watch for file changes using `fswatch`.
     */
    public function watch(Channel $channel): void
    {
        if ($this->stopping) {
            return;
        }

        $this->openProcess();

        try {
            $pipe = $this->pipes[1] ?? null;

            if (! is_resource($pipe)) {
                throw new RuntimeException('The fswatch process did not provide an output pipe.');
            }

            $basePath = base_path();
            $watchPaths = $this->option->getWatchPaths();
            $buffer = '';

            while (true) {
                if ($this->shouldStopWatching($channel)) {
                    return;
                }

                $result = fread($pipe, 8192);

                if ($this->shouldStopWatching($channel)) {
                    return;
                }

                if ($result === false) {
                    throw new RuntimeException('Unable to read output from the fswatch process.');
                }

                if ($result === '') {
                    if (feof($pipe)) {
                        $this->processOutput($buffer, '', $channel, $basePath, $watchPaths, final: true);

                        throw new RuntimeException('The fswatch process exited unexpectedly.');
                    }

                    throw new RuntimeException('Unable to read output from the fswatch process.');
                }

                $this->processOutput($buffer, $result, $channel, $basePath, $watchPaths);
            }
        } finally {
            $this->stop();
        }
    }

    /**
     * Process complete newline-delimited paths while retaining a partial tail.
     *
     * @param list<WatchPath> $watchPaths
     */
    protected function processOutput(
        string &$buffer,
        string $chunk,
        Channel $channel,
        string $basePath,
        array $watchPaths,
        bool $final = false,
    ): void {
        $lines = explode("\n", $buffer . $chunk);
        $buffer = array_pop($lines);

        if ($final && $buffer !== '') {
            $lines[] = $buffer;
            $buffer = '';
        }

        foreach ($lines as $file) {
            if ($file === '') {
                continue;
            }

            $relativePath = substr($file, strlen($basePath) + 1);

            foreach ($watchPaths as $watchPath) {
                if ($watchPath->matches($relativePath)) {
                    $channel->push($file);
                    break;
                }
            }
        }
    }

    /**
     * Stop the fswatch process.
     */
    public function stop(): void
    {
        parent::stop();

        $process = $this->process;
        $this->process = null;

        if (is_resource($process) && proc_get_status($process)['running']) {
            proc_terminate($process, SIGKILL);
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->pipes = [];

        if (is_resource($process)) {
            proc_close($process);
        }
    }

    /**
     * Open the fswatch subprocess and retain its pipes.
     */
    protected function openProcess(): void
    {
        $process = proc_open($this->getCommand(), [['pipe', 'r'], ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('fswatch failed.');
        }

        $this->process = $process;
        $this->pipes = $pipes;
    }

    /**
     * Determine whether the active watch loop should stop.
     *
     * The state may change while hooked I/O yields to another coroutine.
     *
     * @phpstan-impure
     */
    protected function shouldStopWatching(Channel $channel): bool
    {
        return $this->stopping || $channel->isClosing() || $this->process === null;
    }

    /**
     * Build the fswatch command arguments.
     *
     * @return list<string>
     */
    protected function getCommand(): array
    {
        $paths = array_map(
            fn (WatchPath $p) => base_path($p->path),
            $this->option->getWatchPaths(),
        );

        if ($this->isDarwin()) {
            return ['fswatch', ...$paths];
        }

        return [
            'fswatch',
            '-m',
            'inotify_monitor',
            '-E',
            '--format',
            '%p',
            '-r',
            '--event',
            'Created',
            '--event',
            'Updated',
            '--event',
            'Removed',
            '--event',
            'Renamed',
            ...$paths,
        ];
    }
}
