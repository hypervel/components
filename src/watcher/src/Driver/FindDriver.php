<?php

declare(strict_types=1);

namespace Hypervel\Watcher\Driver;

use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Engine\Channel;
use Hypervel\Watcher\Option;
use InvalidArgumentException;

class FindDriver extends AbstractDriver
{
    protected bool $supportsFractionalMinutes;

    protected int $startTime = 0;

    protected array $fileModifyTimes = [];

    public function __construct(
        Option $option,
        protected StdoutLoggerInterface $logger,
    ) {
        parent::__construct($option);

        $bin = $this->getBin();
        $result = $this->exec('which ' . $bin);

        if (empty($result['output'])) {
            throw new InvalidArgumentException(
                $this->isDarwin()
                    ? 'gfind not exists. You can `brew install findutils` to install it.'
                    : 'find not exists.',
            );
        }

        $result = $this->exec($bin . ' --version');
        $this->supportsFractionalMinutes = $result['code'] === 0
            && str_contains($result['output'], 'GNU');
    }

    /**
     * Watch for file changes using the `find` command.
     */
    public function watch(Channel $channel): void
    {
        $this->startTime = time();
        $seconds = $this->option->getScanIntervalSeconds();

        $this->watchAtInterval($seconds, function () use ($channel): void {
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
        if ($this->supportsFractionalMinutes) {
            return sprintf('-%.2f', $minutes);
        }

        return sprintf('-%d', ceil($minutes));
    }

    /**
     * Find changed files in the given targets using the `find` command.
     *
     * @return array{array<string, int>, list<string>, int}
     */
    protected function find(array $fileModifyTimes, array $targets, string $minutes): array
    {
        $changedFiles = [];
        $dest = $this->shellArguments($targets);
        $ret = $this->exec($this->getBin() . ' ' . $dest . ' -mmin ' . $minutes . ' -type f -print');
        if (strlen($ret['output'])) {
            $stdout = trim($ret['output']);

            $lineArr = explode(PHP_EOL, $stdout);
            foreach ($lineArr as $line) {
                $pathName = $line;
                $modifyTime = @filemtime($pathName);
                if ($modifyTime === false || $modifyTime < $this->startTime) {
                    continue;
                }

                if (isset($fileModifyTimes[$pathName]) && $fileModifyTimes[$pathName] === $modifyTime) {
                    continue;
                }
                $fileModifyTimes[$pathName] = $modifyTime;
                $changedFiles[] = $pathName;
            }
        }

        return [$fileModifyTimes, $changedFiles, $ret['code']];
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
        $directoryPaths = $this->option->getDirectoryPaths();
        $failureCode = null;

        // Scan all directories in a single find call.
        $dirs = $this->existingTargets($this->resolveTargets($directoryPaths));

        if ($dirs !== []) {
            $basePath = base_path();
            [$fileModifyTimes, $found, $directoryExitCode] = $this->find($fileModifyTimes, $dirs, $minutes);

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
            [$fileModifyTimes, $changed, $fileExitCode] = $this->find($fileModifyTimes, $files, $minutes);

            if ($fileExitCode !== 0) {
                $failureCode ??= $fileExitCode;
            }

            $changedFiles = array_merge($changedFiles, $changed);
        }

        if ($failureCode !== null) {
            $this->logger->warning(
                "One or more find commands exited with code {$failureCode} while scanning watched paths.",
            );
        }

        return [$fileModifyTimes, $changedFiles];
    }
}
