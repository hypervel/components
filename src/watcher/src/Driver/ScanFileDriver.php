<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Watcher\Option;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
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
            foreach (array_keys($deletedFiles) as $pathName) {
                $channel->push($pathName);
            }

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

            foreach ($modifiedFiles as $pathName) {
                $channel->push($pathName);
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
        $basePath = null;

        // Scan watched directories.
        $directoryPaths = $this->option->getDirectoryPaths();
        $directoryTargets = $this->resolveTargets($directoryPaths);

        foreach ($directoryPaths as $index => $watchPath) {
            try {
                $allFiles = $this->filesystem->allFiles($directoryTargets[$index]);
            } catch (DirectoryNotFoundException) {
                continue;
            }

            /** @var SplFileInfo $obj */
            foreach ($allFiles as $obj) {
                $pathName = $obj->getPathName();
                $basePath ??= base_path();
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
        foreach ($this->resolveTargets($this->option->getFilePaths()) as $pathName) {
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
