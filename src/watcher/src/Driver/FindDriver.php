<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use InvalidArgumentException;

class FindDriver extends AbstractDriver
{
    protected bool $isSupportFloatMinutes = true;

    protected int $startTime = 0;

    protected array $fileModifyTimes = [];

    public function __construct(protected Option $option)
    {
        parent::__construct($option);

        if ($this->isDarwin()) {
            $ret = $this->exec('which gfind');
            if (empty($ret['output'])) {
                throw new InvalidArgumentException('gfind not exists. You can `brew install findutils` to install it.');
            }
        } else {
            $ret = $this->exec('which find');
            if (empty($ret['output'])) {
                throw new InvalidArgumentException('find not exists.');
            }
            $ret = $this->exec('find --help');
            $this->isSupportFloatMinutes = ! str_contains($ret['output'], 'BusyBox');
        }
    }

    /**
     * Watch for file changes using the `find` command.
     */
    public function watch(Channel $channel): void
    {
        $this->startTime = time();
        $seconds = $this->option->getScanIntervalSeconds();

        $this->timerId = $this->timer->tick($seconds, function () use ($channel) {
            [$this->fileModifyTimes, $changedFiles] = $this->scan($this->fileModifyTimes, $this->getScanIntervalMinutes());

            foreach ($changedFiles as $file) {
                $channel->push($file);
            }
        });
    }

    /**
     * Get the scan interval as a `find -mmin` compatible minutes string.
     */
    protected function getScanIntervalMinutes(): string
    {
        $minutes = $this->option->getScanIntervalSeconds() / 60;
        if ($this->isSupportFloatMinutes) {
            return sprintf('-%.2f', $minutes);
        }
        return sprintf('-%d', ceil($minutes));
    }

    /**
     * Find changed files in the given targets using the `find` command.
     */
    protected function find(array $fileModifyTimes, array $targets, string $minutes): array
    {
        $changedFiles = [];
        $dest = implode(' ', $targets);
        $ret = $this->exec($this->getBin() . ' ' . $dest . ' -mmin ' . $minutes . ' -type f -print');
        if ($ret['code'] === 0 && strlen($ret['output'])) {
            $stdout = trim($ret['output']);

            $lineArr = explode(PHP_EOL, $stdout);
            foreach ($lineArr as $line) {
                $pathName = $line;
                $modifyTime = filemtime($pathName);
                if ($modifyTime <= $this->startTime) {
                    continue;
                }

                if (isset($fileModifyTimes[$pathName]) && $fileModifyTimes[$pathName] === $modifyTime) {
                    continue;
                }
                $fileModifyTimes[$pathName] = $modifyTime;
                $changedFiles[] = $pathName;
            }
        }

        return [$fileModifyTimes, $changedFiles];
    }

    /**
     * Get the `find` binary name for the current OS.
     */
    protected function getBin(): string
    {
        return $this->isDarwin() ? 'gfind' : 'find';
    }

    /**
     * Scan watched directories and files for changes.
     */
    protected function scan(array $fileModifyTimes, string $minutes): array
    {
        $changedFiles = [];
        $basePath = base_path();
        $directoryPaths = $this->option->getDirectoryPaths();

        // Scan all directories in a single find call.
        $dirs = array_map(
            fn (WatchPath $p) => base_path($p->path),
            $directoryPaths,
        );

        if ($dirs !== []) {
            [$fileModifyTimes, $found] = $this->find($fileModifyTimes, $dirs, $minutes);
            foreach ($found as $file) {
                $relativePath = substr($file, strlen($basePath) + 1);
                foreach ($directoryPaths as $watchPath) {
                    if ($watchPath->matches($relativePath)) {
                        $changedFiles[] = $file;
                        break;
                    }
                }
            }
        }

        // Check individual watched files.
        $files = array_map(
            fn (WatchPath $p) => base_path($p->path),
            $this->option->getFilePaths(),
        );

        if ($files !== []) {
            [$fileModifyTimes, $changed] = $this->find($fileModifyTimes, $files, $minutes);
            $changedFiles = array_merge($changedFiles, $changed);
        }

        return [$fileModifyTimes, $changedFiles];
    }
}
