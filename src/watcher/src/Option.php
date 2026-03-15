<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

use Hypervel\Watcher\Driver\ScanFileDriver;

class Option
{
    protected string $driver = ScanFileDriver::class;

    protected string $bin = PHP_BINARY;

    protected string $command = 'artisan serve';

    /**
     * @var string[]
     */
    protected array $watchDir = ['app', 'config'];

    /**
     * @var string[]
     */
    protected array $watchFile = ['.env'];

    /**
     * @var string[]
     */
    protected array $ext = ['.php', '.env'];

    protected int $scanInterval = 2000;

    public function __construct(array $options = [], array $dir = [], array $file = [], protected bool $restart = true)
    {
        isset($options['driver']) && $this->driver = $options['driver'];
        isset($options['bin']) && $this->bin = $options['bin'];
        isset($options['command']) && $this->command = $options['command'];
        isset($options['watch']['dir']) && $this->watchDir = (array) $options['watch']['dir'];
        isset($options['watch']['file']) && $this->watchFile = (array) $options['watch']['file'];
        isset($options['watch']['scan_interval']) && $this->scanInterval = (int) $options['watch']['scan_interval'];
        isset($options['ext']) && $this->ext = (array) $options['ext'];

        $this->watchDir = array_unique(array_merge($this->watchDir, $dir));
        $this->watchFile = array_unique(array_merge($this->watchFile, $file));
    }

    /**
     * Get the watcher driver class name.
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * Get the PHP binary path, quoted if it contains spaces.
     */
    public function getBin(): string
    {
        if (str_contains($this->bin, ' ')) {
            // If the binary path contains spaces, we need to wrap it in quotes.
            return '"' . $this->bin . '"';
        }

        return $this->bin;
    }

    /**
     * Get the server start command.
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * Get the directories to watch.
     *
     * @return string[]
     */
    public function getWatchDir(): array
    {
        return $this->watchDir;
    }

    /**
     * Get the individual files to watch.
     *
     * @return string[]
     */
    public function getWatchFile(): array
    {
        return $this->watchFile;
    }

    /**
     * Get the file extensions to watch.
     *
     * @return string[]
     */
    public function getExt(): array
    {
        return $this->ext;
    }

    /**
     * Get the scan interval in milliseconds.
     */
    public function getScanInterval(): int
    {
        return $this->scanInterval > 0 ? $this->scanInterval : 2000;
    }

    /**
     * Get the scan interval in seconds.
     */
    public function getScanIntervalSeconds(): float
    {
        return $this->getScanInterval() / 1000;
    }

    /**
     * Determine if the server should be restarted on file changes.
     */
    public function isRestart(): bool
    {
        return $this->restart;
    }
}
