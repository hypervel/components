<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Watcher\Option;
use Symfony\Component\Finder\SplFileInfo;

class ScanFileDriver extends AbstractDriver
{
    protected Filesystem $filesystem;

    protected ?array $lastMD5 = null;

    public function __construct(protected Option $option, private StdoutLoggerInterface $logger)
    {
        parent::__construct($option);
        $this->filesystem = new Filesystem;
    }

    /**
     * Watch for file changes by polling MD5 checksums.
     */
    public function watch(Channel $channel): void
    {
        $seconds = $this->option->getScanIntervalSeconds();
        $this->timerId = $this->timer->tick($seconds, function () use ($channel) {
            $currentMD5 = $this->getWatchMD5();
            if ($this->lastMD5 && $this->lastMD5 !== $currentMD5) {
                // Added files (in current but not in last).
                $addedFiles = array_diff_key($currentMD5, $this->lastMD5);
                foreach ($addedFiles as $pathName => $md5) {
                    $channel->push($pathName);
                }

                // Deleted files (in last but not in current).
                $deletedFiles = array_diff_key($this->lastMD5, $currentMD5);

                // Modified files (same path, different hash).
                $modifiedFiles = [];
                foreach ($currentMD5 as $pathName => $md5) {
                    if (isset($this->lastMD5[$pathName]) && $this->lastMD5[$pathName] !== $md5) {
                        $modifiedFiles[] = $pathName;
                    }
                }

                $this->logger->debug(sprintf(
                    '%s Watching: Total:%d, Change:%d, Add:%d, Delete:%d.',
                    self::class,
                    count($currentMD5),
                    count($modifiedFiles),
                    count($addedFiles),
                    count($deletedFiles),
                ));

                if (count($deletedFiles) === 0) {
                    foreach ($modifiedFiles as $pathName) {
                        $channel->push($pathName);
                    }
                } else {
                    $this->logger->warning('Delete files must be restarted manually to take effect.');
                }
            }
            $this->lastMD5 = $currentMD5;
        });
    }

    /**
     * Compute MD5 checksums for all watched files.
     */
    protected function getWatchMD5(): array
    {
        $filesMD5 = [];
        $basePath = base_path();

        // Scan watched directories.
        foreach ($this->option->getDirectoryPaths() as $watchPath) {
            $allFiles = $this->filesystem->allFiles(base_path($watchPath->path));
            /** @var SplFileInfo $obj */
            foreach ($allFiles as $obj) {
                $pathName = $obj->getPathName();
                $relativePath = substr($pathName, strlen($basePath) + 1);
                if (! $watchPath->matches($relativePath)) {
                    continue;
                }
                $filesMD5[$pathName] = md5(file_get_contents($pathName));
            }
        }

        // Check individual watched files.
        foreach ($this->option->getFilePaths() as $watchPath) {
            $pathName = base_path($watchPath->path);
            if (file_exists($pathName)) {
                $filesMD5[$pathName] = md5(file_get_contents($pathName));
            }
        }

        return $filesMD5;
    }
}
