<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Support\Str;
use Hypervel\Watcher\Option;
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

        while (! $channel->isClosing()) {
            $ret = fread($pipes[1], 8192);
            if (is_string($ret) && $ret !== '') {
                Coroutine::create(function () use ($ret, $channel) {
                    $files = array_filter(explode("\n", $ret));
                    foreach ($files as $file) {
                        if (Str::endsWith($file, $this->option->getExt())) {
                            $channel->push($file);
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
            $running = proc_get_status($this->process)['running'];
            // Kill the child process to exit.
            $running && proc_terminate($this->process, SIGKILL);
        }
    }

    /**
     * Build the fswatch command string.
     */
    protected function getCmd(): string
    {
        $dir = $this->option->getWatchDir();
        $file = $this->option->getWatchFile();

        $cmd = 'fswatch ';
        if (! $this->isDarwin()) {
            $cmd .= ' -m inotify_monitor';
            $cmd .= " -E --format '%p' -r ";
            $cmd .= ' --event Created --event Updated --event Removed --event Renamed ';
        }

        return $cmd . implode(' ', $dir) . ' ' . implode(' ', $file);
    }
}
