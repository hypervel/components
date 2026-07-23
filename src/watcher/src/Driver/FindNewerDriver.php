<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Channel;
use Hypervel\Watcher\Option;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FindNewerDriver extends AbstractDriver
{
    /** @var list<string> */
    protected array $referenceFiles = [];

    protected bool $scanning = false;

    protected int $count = 0;

    public function __construct(
        Option $option,
        protected StdoutLoggerInterface $logger,
    ) {
        parent::__construct($option);

        $ret = $this->exec('which find');
        if (empty($ret['output'])) {
            throw new InvalidArgumentException('find not exists.');
        }
        $this->ensureReferenceFiles();
    }

    /**
     * Watch for file changes using `find -newer`.
     */
    public function watch(Channel $channel): void
    {
        if ($this->scanning) {
            throw new RuntimeException('Cannot restart the find-newer watcher while its previous scan is still stopping.');
        }

        if ($this->stopping) {
            return;
        }

        $this->ensureReferenceFiles();

        $seconds = $this->option->getScanIntervalSeconds();
        $this->watchAtInterval($seconds, function () use ($channel): void {
            if ($this->scanning || $this->stopping) {
                return;
            }
            $this->scanning = true;
            try {
                // Record the next cutoff before scanning so changes made after
                // find passes their path remain eligible on the next tick.
                $this->updateReferenceFile($this->getToModifyFile());

                if ($this->stopping) {
                    return;
                }

                [$changedFiles, $failureCode] = $this->scan();

                if ($this->stopping) {
                    return;
                }

                if ($failureCode === null) {
                    // Every successful scan swaps reference roles, including a
                    // quiet scan, so the pre-recorded cutoff becomes authoritative.
                    ++$this->count;
                } else {
                    $this->logger->warning(
                        "One or more find commands exited with code {$failureCode} while scanning watched paths.",
                    );
                }

                foreach ($changedFiles as $file) {
                    $channel->push($file);
                }
            } finally {
                $this->scanning = false;

                if ($this->stopping) {
                    $this->removeReferenceFiles();
                }
            }
        });
    }

    /**
     * Stop watching and remove this driver's reference files.
     */
    public function stop(): void
    {
        parent::stop();

        if (! $this->scanning) {
            $this->removeReferenceFiles();
        }
    }

    /**
     * Find files newer than the reference file in the given targets.
     *
     * @return array{list<string>, int}
     */
    protected function find(array $targets): array
    {
        $changedFiles = [];
        $referenceFile = $this->shellArguments([$this->getToScanFile()]);
        $ret = $this->exec(sprintf(
            'find %s -newer %s -type f -print',
            $this->shellArguments($targets),
            $referenceFile,
        ));

        if (strlen($ret['output'])) {
            $stdout = $ret['output'];
            $lineArr = explode(PHP_EOL, $stdout);
            foreach ($lineArr as $pathName) {
                if (empty($pathName)) {
                    continue;
                }

                $changedFiles[] = $pathName;
            }
        }

        return [$changedFiles, $ret['code']];
    }

    /**
     * Scan watched directories and files for changes.
     *
     * The coroutine-aware find command may yield while stop state changes.
     *
     * @return array{list<string>, null|int}
     *
     * @phpstan-impure
     */
    protected function scan(): array
    {
        $changedFiles = [];
        $basePath = base_path();
        $directoryPaths = $this->option->getDirectoryPaths();
        $failureCode = null;

        // Scan all directories in a single find call.
        $dirs = $this->existingTargets($this->resolveTargets($directoryPaths));

        if ($dirs !== []) {
            [$found, $directoryExitCode] = $this->find($dirs);

            if ($directoryExitCode !== 0) {
                $failureCode = $directoryExitCode;
            }

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
        $files = $this->existingTargets($this->resolveTargets($this->option->getFilePaths()));

        if ($files !== []) {
            [$changed, $fileExitCode] = $this->find($files);

            if ($fileExitCode !== 0) {
                $failureCode ??= $fileExitCode;
            }

            $changedFiles = array_merge($changedFiles, $changed);
        }

        return [$changedFiles, $failureCode];
    }

    /**
     * Get the path to the reference file to be modified.
     */
    protected function getToModifyFile(): string
    {
        return $this->referenceFiles[$this->count % 2];
    }

    /**
     * Get the path to the reference file used for scanning.
     */
    protected function getToScanFile(): string
    {
        return $this->referenceFiles[($this->count + 1) % 2];
    }

    /**
     * Ensure this driver owns two reference files for change comparisons.
     */
    protected function ensureReferenceFiles(): void
    {
        try {
            while (count($this->referenceFiles) < 2) {
                $this->referenceFiles[] = $this->createReferenceFile();
            }
        } catch (Throwable $exception) {
            $this->removeReferenceFiles();

            throw $exception;
        }
    }

    /**
     * Create a unique reference file owned by this driver.
     */
    protected function createReferenceFile(): string
    {
        $path = @tempnam(sys_get_temp_dir(), 'hypervel-watcher-find-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a watcher reference file.');
        }

        return $path;
    }

    /**
     * Create or update a reference file used by `find -newer`.
     */
    protected function updateReferenceFile(string $path): void
    {
        if (! @touch($path)) {
            throw new RuntimeException("Unable to update the watcher reference file [{$path}].");
        }
    }

    /**
     * Remove every reference file currently owned by this driver.
     */
    protected function removeReferenceFiles(): void
    {
        $files = $this->referenceFiles;
        $this->referenceFiles = [];

        foreach ($files as $file) {
            if ((is_file($file) || is_link($file)) && ! @unlink($file)) {
                error_log("Unable to remove the watcher reference file [{$file}].");
            }
        }
    }
}
