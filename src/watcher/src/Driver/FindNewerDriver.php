<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Engine\Channel;
use Hypervel\Watcher\Option;
use Hypervel\Watcher\WatchPath;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FindNewerDriver extends AbstractDriver
{
    /** @var list<string> */
    protected array $referenceFiles = [];

    protected bool $scanning = false;

    protected bool $stopping = false;

    protected int $count = 0;

    public function __construct(protected Option $option)
    {
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

        $this->stopping = false;
        $this->ensureReferenceFiles();

        $seconds = $this->option->getScanIntervalSeconds();
        $this->timerId = $this->timer->tick($seconds, function () use ($channel) {
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

                $changedFiles = $this->scan();

                if ($this->stopping) {
                    return;
                }

                // Every successful scan swaps reference roles, including a
                // quiet scan, so the pre-recorded cutoff becomes authoritative.
                ++$this->count;

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
        $this->stopping = true;

        parent::stop();

        if (! $this->scanning) {
            $this->removeReferenceFiles();
        }
    }

    /**
     * Find files newer than the reference file in the given targets.
     */
    protected function find(array $targets): array
    {
        $changedFiles = [];

        $commands = [];
        $referenceFile = $this->shellArguments([$this->getToScanFile()]);

        foreach ($targets as $target) {
            $commands[] = sprintf(
                'find %s -newer %s -type f',
                $this->shellArguments([$target]),
                $referenceFile,
            );
        }

        $ret = $this->exec(implode('&', $commands));
        if ($ret['code'] === 0 && strlen($ret['output'])) {
            $stdout = $ret['output'];
            $lineArr = explode(PHP_EOL, $stdout);
            foreach ($lineArr as $pathName) {
                if (empty($pathName)) {
                    continue;
                }

                $changedFiles[] = $pathName;
            }
        }

        return $changedFiles;
    }

    /**
     * Scan watched directories and files for changes.
     *
     * The coroutine-aware find command may yield while stop state changes.
     *
     * @phpstan-impure
     */
    protected function scan(): array
    {
        $changedFiles = [];
        $basePath = base_path();
        $directoryPaths = $this->option->getDirectoryPaths();

        // Scan all directories in a single parallelised find call.
        $dirs = array_map(
            fn (WatchPath $p) => base_path($p->path),
            $directoryPaths,
        );

        if ($dirs !== []) {
            $found = $this->find($dirs);
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
            $changedFiles = array_merge($changedFiles, $this->find($files));
        }

        return $changedFiles;
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
