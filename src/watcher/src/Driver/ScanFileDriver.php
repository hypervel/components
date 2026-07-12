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

    /**
     * @var null|array<string, string>
     */
    protected ?array $lastFileHashes = null;

    public function __construct(
        protected Option $option,
        private StdoutLoggerInterface $logger,
        ?Filesystem $filesystem = null
    ) {
        parent::__construct($option);

        $this->filesystem = $filesystem ?? new Filesystem;
    }

    /**
     * Watch for file changes by polling file hashes.
     */
    public function watch(Channel $channel): void
    {
        $seconds = $this->option->getScanIntervalSeconds();
        $this->watchAtInterval($seconds, function () use ($channel): void {
            $this->processFileHashes($channel, $this->getWatchFileHashes());
        });
    }

    /**
     * Process a new file hash snapshot.
     *
     * @param array<string, string> $currentFileHashes
     */
    protected function processFileHashes(Channel $channel, array $currentFileHashes): void
    {
        if ($this->lastFileHashes !== null && $this->lastFileHashes !== $currentFileHashes) {
            // Added files (in current but not in last).
            $addedFiles = array_diff_key($currentFileHashes, $this->lastFileHashes);
            foreach (array_keys($addedFiles) as $pathName) {
                $channel->push($pathName);
            }

            // Deleted files (in last but not in current).
            $deletedFiles = array_diff_key($this->lastFileHashes, $currentFileHashes);

            // Modified files (same path, different hash).
            $modifiedFiles = [];
            foreach ($currentFileHashes as $pathName => $fileHash) {
                if (isset($this->lastFileHashes[$pathName]) && $this->lastFileHashes[$pathName] !== $fileHash) {
                    $modifiedFiles[] = $pathName;
                }
            }

            $this->logger->debug(sprintf(
                '%s Watching: Total:%d, Change:%d, Add:%d, Delete:%d.',
                self::class,
                count($currentFileHashes),
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

        $this->lastFileHashes = $currentFileHashes;
    }

    /**
     * Compute hashes for all watched files.
     *
     * @return array<string, string>
     */
    protected function getWatchFileHashes(): array
    {
        $fileHashes = [];
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
                $fileHash = $this->hashFile($pathName);
                if ($fileHash !== null) {
                    $fileHashes[$pathName] = $fileHash;
                }
            }
        }

        // Check individual watched files.
        foreach ($this->option->getFilePaths() as $watchPath) {
            $pathName = base_path($watchPath->path);
            if (file_exists($pathName)) {
                $fileHash = $this->hashFile($pathName);
                if ($fileHash !== null) {
                    $fileHashes[$pathName] = $fileHash;
                }
            }
        }

        return $fileHashes;
    }

    /**
     * Hash a watched file.
     */
    protected function hashFile(string $path): ?string
    {
        $hash = $this->filesystem->hash($path);

        return $hash === false ? null : $hash;
    }
}
