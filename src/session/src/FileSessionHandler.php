<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\CarbonImmutable;
use SessionHandlerInterface;
use Symfony\Component\Finder\Finder;

class FileSessionHandler implements SessionHandlerInterface
{
    /**
     * Create a new file driven handler instance.
     *
     * @param Filesystem $files the filesystem instance
     * @param string $path the path where sessions should be stored
     * @param int $minutes the number of minutes the session should be valid
     */
    public function __construct(
        protected Filesystem $files,
        protected string $path,
        protected int $minutes
    ) {
    }

    public function open(string $savePath, string $sessionName): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $sessionId): false|string
    {
        if ($this->files->isFile($path = $this->path . '/' . $sessionId)
            && $this->files->lastModified($path) >= CarbonImmutable::now()->subMinutes($this->minutes)->getTimestamp()
        ) {
            try {
                return $this->files->sharedGet($path);
            } catch (FileNotFoundException) {
                return '';
            }
        }

        return '';
    }

    public function write(string $sessionId, string $data): bool
    {
        return $this->files->put($this->path . '/' . $sessionId, $data, true) === strlen($data);
    }

    public function destroy(string $sessionId): bool
    {
        $this->files->delete($this->path . '/' . $sessionId);

        return true;
    }

    public function gc(int $lifetime): int
    {
        $files = Finder::create()
            ->in($this->path)
            ->files()
            ->ignoreDotFiles(true)
            ->date('<= now - ' . $lifetime . ' seconds');

        $deletedSessions = 0;

        foreach ($files as $file) {
            if ($this->files->delete($file->getPathname())) {
                ++$deletedSessions;
            }
        }

        return $deletedSessions;
    }
}
