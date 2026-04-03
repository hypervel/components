<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Support\Str;
use Hypervel\Watcher\Option;
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
    protected function find(array $fileModifyTimes, array $targets, string $minutes, array $ext = []): array
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
                if (! empty($ext) && ! Str::endsWith($pathName, $ext)) {
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
        $ext = $this->option->getExt();

        $dirs = array_map(fn ($dir) => base_path($dir), $this->option->getWatchDir());

        [$fileModifyTimes, $changedFilesInDirs] = $this->find($fileModifyTimes, $dirs, $minutes, $ext);

        $files = array_map(fn ($file) => base_path($file), $this->option->getWatchFile());

        [$fileModifyTimes, $changedFiles] = $this->find($fileModifyTimes, $files, $minutes);

        return [$fileModifyTimes, array_merge($changedFilesInDirs, $changedFiles)];
    }
}
