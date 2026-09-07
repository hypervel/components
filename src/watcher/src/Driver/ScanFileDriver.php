<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Channel;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Watcher\Option;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Finder\Finder;
use UnexpectedValueException;

class ScanFileDriver extends AbstractDriver
{
    protected Filesystem $filesystem;

    /** @var null|array<string, string> */
    protected ?array $lastFileHashes = null;

    public function __construct(
        Option $option,
        protected StdoutLoggerInterface $logger,
        ?Filesystem $filesystem = null,
    ) {
        parent::__construct($option);

        $this->filesystem = $filesystem ?? new Filesystem;
    }

    /**
     * Watch for file changes by polling file hashes.
     */
    public function watch(Channel $channel): void
    {
        $this->watchAtInterval($this->option->getScanIntervalSeconds(), function () use ($channel): void {
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
        if ($this->lastFileHashes !== null) {
            $addedFiles = array_diff_key($currentFileHashes, $this->lastFileHashes);
            $deletedFiles = array_diff_key($this->lastFileHashes, $currentFileHashes);
            $modifiedFiles = [];

            foreach ($currentFileHashes as $pathName => $fileHash) {
                if (isset($this->lastFileHashes[$pathName]) && $this->lastFileHashes[$pathName] !== $fileHash) {
                    $modifiedFiles[] = $pathName;
                }
            }

            if ($addedFiles !== [] || $deletedFiles !== [] || $modifiedFiles !== []) {
                $this->logger->debug(sprintf(
                    '%s Watching: Total:%d, Change:%d, Add:%d, Delete:%d.',
                    self::class,
                    count($currentFileHashes),
                    count($modifiedFiles),
                    count($addedFiles),
                    count($deletedFiles),
                ));

                foreach (array_keys($addedFiles) as $pathName) {
                    $channel->push($pathName);
                }

                foreach (array_keys($deletedFiles) as $pathName) {
                    $channel->push($pathName);
                }

                foreach ($modifiedFiles as $pathName) {
                    $channel->push($pathName);
                }
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
        $basePathLength = strlen(base_path()) + 1;
        $directoryPaths = $this->option->getDirectoryPaths();
        $targetGroups = $this->groupWatchPathsByTarget($directoryPaths);

        foreach ($targetGroups as $target => $group) {
            try {
                $finder = Finder::create()
                    ->files()
                    ->ignoreDotFiles(false)
                    ->ignoreUnreadableDirs()
                    ->in($target);

                if (! $group['recursive']) {
                    $finder->depth(0);
                }

                foreach ($finder as $file) {
                    $pathName = $file->getPathname();

                    if (isset($fileHashes[$pathName])) {
                        continue;
                    }

                    // Finder preserves the target spelling, unlike fswatch's canonicalized output.
                    $relativePath = substr($pathName, $basePathLength);

                    foreach ($group['watchPaths'] as $watchPath) {
                        if (! $watchPath->matches($relativePath)) {
                            continue;
                        }

                        $fileHash = $this->hashFile($pathName);

                        if ($fileHash !== null) {
                            $fileHashes[$pathName] = $fileHash;
                        }

                        break;
                    }
                }
            } catch (DirectoryNotFoundException|UnexpectedValueException) {
                // RecursiveDirectoryIterator throws while opening an unreadable root before Finder can skip unreadable children.
                continue;
            }
        }

        foreach ($this->resolveTargets($this->option->getFilePaths()) as $pathName) {
            if (isset($fileHashes[$pathName]) || ! is_file($pathName)) {
                continue;
            }

            $fileHash = $this->hashFile($pathName);

            if ($fileHash !== null) {
                $fileHashes[$pathName] = $fileHash;
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
