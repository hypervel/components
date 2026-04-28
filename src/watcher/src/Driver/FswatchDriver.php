<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use InvalidArgumentException;
use RuntimeException;

class FswatchDriver extends AbstractDriver
{
    protected mixed $process = null;

    public function __construct(protected Option $option)
    {
        parent::__construct($option);
        $ret = $this->exec('which fswatch');
        if (empty($ret['output'])) {
            throw new InvalidArgumentException('fswatch not exists. You can `brew install fswatch` to install it.');
        }
    }

    /**
     * Watch for file changes using `fswatch`.
     */
    public function watch(Channel $channel): void
    {
        $cmd = $this->getCmd();
        $this->process = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w']], $pipes);
        if (! is_resource($this->process)) {
            throw new RuntimeException('fswatch failed.');
        }

        $basePath = base_path();
        $watchPaths = $this->option->getWatchPaths();

        while (! $channel->isClosing()) {
            $ret = fread($pipes[1], 8192);
            if (is_string($ret) && $ret !== '') {
                Coroutine::create(function () use ($ret, $channel, $basePath, $watchPaths) {
                    $files = array_filter(explode("\n", $ret));
                    foreach ($files as $file) {
                        $relativePath = substr($file, strlen($basePath) + 1);
                        foreach ($watchPaths as $watchPath) {
                            if ($watchPath->matches($relativePath)) {
                                $channel->push($file);
                                break;
                            }
                        }
                    }
                });
            }
        }
    }

    /**
     * Stop the fswatch process.
     */
    public function stop(): void
    {
        parent::stop();

        if (is_resource($this->process)) {
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process, SIGKILL);
            }
            proc_close($this->process);
        }
    }

    /**
     * Build the fswatch command string.
     */
    protected function getCmd(): string
    {
        $paths = array_map(
            fn (WatchPath $p) => base_path($p->path),
            $this->option->getWatchPaths(),
        );

        $cmd = 'fswatch ';
        if (! $this->isDarwin()) {
            $cmd .= ' -m inotify_monitor';
            $cmd .= " -E --format '%p' -r ";
            $cmd .= ' --event Created --event Updated --event Removed --event Renamed ';
        }

        return $cmd . implode(' ', $paths);
    }
}
