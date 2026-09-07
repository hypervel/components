<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Channel;
use Hypervel\Watcher\Option;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FindDriver extends AbstractDriver
{
    /** @var list<string> */
    protected array $referenceFiles = [];

    protected int $activeReferenceIndex = 0;

    /** @var array<string, true> */
    protected array $inventory = [];

    protected bool $hasCompleteInventory = false;

    public function __construct(
        Option $option,
        protected StdoutLoggerInterface $logger,
    ) {
        parent::__construct($option);

        if ($this->exec('command -v find')['code'] !== 0) {
            throw new InvalidArgumentException('The FindDriver requires the `find` executable.');
        }
    }

    /**
     * Watch for file changes using `find -newer`.
     */
    public function watch(Channel $channel): void
    {
        if ($this->isStopping()) {
            return;
        }

        $this->ensureReferenceFiles();

        try {
            $this->watchAtInterval($this->option->getScanIntervalSeconds(), function () use ($channel): void {
                $this->updateReferenceFile($this->inactiveReferenceFile());

                if ($this->isStopping()) {
                    return;
                }

                [
                    'files' => $changedFiles,
                    'changedComplete' => $changedComplete,
                    'inventoryComplete' => $inventoryComplete,
                    'failureCode' => $failureCode,
                ] = $this->scan();

                if ($this->isStopping()) {
                    return;
                }

                if ($changedComplete) {
                    $this->rotateReferenceFiles();
                }

                if ($failureCode !== null) {
                    $this->logDegradedCycle($failureCode, $changedComplete, $inventoryComplete);
                }

                foreach ($changedFiles as $file) {
                    if ($this->isStopping()) {
                        return;
                    }

                    $channel->push($file);
                }
            });
        } finally {
            $this->removeReferenceFiles();
        }
    }

    /**
     * Scan watched targets for changed and currently live files.
     *
     * @return array{
     *     files: list<string>,
     *     changedComplete: bool,
     *     inventoryComplete: bool,
     *     failureCode: null|int
     * }
     */
    protected function scan(): array
    {
        $targetDefinitions = $this->groupWatchPathsByTarget($this->option->getWatchPaths());

        $targetGroups = [[], []];
        foreach ($this->existingTargets(array_keys($targetDefinitions)) as $target) {
            $targetGroups[(int) $targetDefinitions[$target]['recursive']][] = $target;
        }

        $changedFiles = [];
        $currentInventory = [];
        $changedComplete = true;
        $inventoryComplete = true;
        $failureCode = null;

        foreach ([false, true] as $recursive) {
            $targets = $targetGroups[(int) $recursive];

            if ($targets === []) {
                continue;
            }

            [$foundChanges, $changedExitCode] = $this->find($targets, $recursive, changed: true);
            $changedFiles += $foundChanges;

            if ($changedExitCode !== 0) {
                $changedComplete = false;
                $failureCode ??= $changedExitCode;
            }

            [$foundInventory, $inventoryExitCode] = $this->find($targets, $recursive, changed: false);
            $currentInventory += $foundInventory;

            if ($inventoryExitCode !== 0) {
                $inventoryComplete = false;
                $failureCode ??= $inventoryExitCode;
            }
        }

        return [
            'files' => $this->reconcileInventory($changedFiles, $currentInventory, $inventoryComplete),
            'changedComplete' => $changedComplete,
            'inventoryComplete' => $inventoryComplete,
            'failureCode' => $failureCode,
        ];
    }

    /**
     * Report the guarantees affected by a degraded scan cycle.
     */
    protected function logDegradedCycle(
        int $failureCode,
        bool $changedComplete,
        bool $inventoryComplete,
    ): void {
        $effects = [];

        if (! $changedComplete) {
            $effects[] = 'Detected changes may repeat until the filesystem error is fixed.';
        }

        if (! $inventoryComplete) {
            $effects[] = 'Deletion detection is suspended until the filesystem error is fixed.';
        }

        $this->logger->warning(
            "One or more find commands exited with code {$failureCode}. " . implode(' ', $effects),
        );
    }

    /**
     * Run one changed-file or inventory traversal.
     *
     * @param list<string> $targets
     * @return array{array<string, true>, int}
     */
    protected function find(array $targets, bool $recursive, bool $changed): array
    {
        $command = 'find -H ' . $this->shellArguments($targets);

        if (! $recursive) {
            $command .= ' -maxdepth 1';
        }

        if ($changed) {
            $command .= ' -newer ' . $this->shellArguments([$this->activeReferenceFile()]);
        }

        $result = $this->exec($command . ' -type f -print0');

        return [$this->matchingFiles($result['output']), $result['code']];
    }

    /**
     * Parse complete matching paths from NUL-delimited command output.
     *
     * @return array<string, true>
     */
    protected function matchingFiles(string $output): array
    {
        $files = [];
        $offset = 0;
        $basePathLength = strlen(base_path()) + 1;
        $watchPaths = $this->option->getWatchPaths();

        // Filter as records are parsed so a broad target never creates a second all-candidate list.
        while (($separator = strpos($output, "\0", $offset)) !== false) {
            $file = substr($output, $offset, $separator - $offset);
            $offset = $separator + 1;

            if ($file === '') {
                continue;
            }

            // find preserves the operand spelling, unlike fswatch's canonicalized output.
            $relativePath = substr($file, $basePathLength);

            foreach ($watchPaths as $watchPath) {
                if ($watchPath->matches($relativePath)) {
                    $files[$file] = true;
                    break;
                }
            }
        }

        return $files;
    }

    /**
     * Reconcile changed files with the latest complete or partial inventory.
     *
     * @param array<string, true> $changedFiles
     * @param array<string, true> $currentInventory
     * @return list<string>
     */
    protected function reconcileInventory(
        array $changedFiles,
        array $currentInventory,
        bool $inventoryComplete,
    ): array {
        if (! $inventoryComplete) {
            $this->inventory += $changedFiles;

            return array_keys($changedFiles);
        }

        $additions = array_diff_key($currentInventory, $this->inventory);

        if (! $this->hasCompleteInventory) {
            $additions = array_intersect_key($additions, $changedFiles);
        }

        $deletions = array_diff_key($this->inventory, $currentInventory);
        $modifications = array_diff_key(
            array_intersect_key($changedFiles, $currentInventory),
            $additions,
        );

        $this->inventory = $currentInventory;
        $this->hasCompleteInventory = true;

        return array_keys($additions + $deletions + $modifications);
    }

    /**
     * Ensure this lifecycle owns two unique reference files.
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
     * Create a unique reference file.
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
     * Update a reference file to the current filesystem timestamp.
     */
    protected function updateReferenceFile(string $path): void
    {
        if (! @touch($path)) {
            throw new RuntimeException("Unable to update the watcher reference file [{$path}].");
        }
    }

    /**
     * Return the reference that bounds the current changed traversal.
     */
    protected function activeReferenceFile(): string
    {
        return $this->referenceFiles[$this->activeReferenceIndex];
    }

    /**
     * Return the reference that will bound the next changed traversal.
     */
    protected function inactiveReferenceFile(): string
    {
        return $this->referenceFiles[1 - $this->activeReferenceIndex];
    }

    /**
     * Make the pre-recorded next cutoff authoritative.
     */
    protected function rotateReferenceFiles(): void
    {
        $this->activeReferenceIndex = 1 - $this->activeReferenceIndex;
    }

    /**
     * Remove every reference file owned by this lifecycle.
     */
    protected function removeReferenceFiles(): void
    {
        $files = $this->referenceFiles;
        $this->referenceFiles = [];
        $this->activeReferenceIndex = 0;

        foreach ($files as $file) {
            if (is_file($file) && ! @unlink($file)) {
                $this->logger->warning("Unable to remove the watcher reference file [{$file}].");
            }
        }
    }
}
