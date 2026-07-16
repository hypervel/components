<?php

declare(strict_types=1);

namespace Hypervel\Log\Handlers;

use Hypervel\Log\Handlers\Concerns\PerformsSafeStreamOperations;
use Monolog\Handler\RotatingFileHandler as MonologRotatingFileHandler;
use Monolog\LogRecord;
use Override;
use Throwable;

class RotatingFileHandler extends MonologRotatingFileHandler
{
    use PerformsSafeStreamOperations;

    #[Override]
    public function close(): void
    {
        $this->closeStreamSafely();

        if ($this->mustRotate === true) {
            $this->rotate();
        }
    }

    #[Override]
    protected function write(LogRecord $record): void
    {
        if ($this->mustRotate === null) {
            $this->mustRotate = $this->url === null || ! file_exists($this->url);
        }

        if ($this->nextRotation <= $record->datetime) {
            $this->mustRotate = true;
            $this->close();
        }

        $this->writeStreamSafely($record);

        if ($this->mustRotate === true) {
            $this->close();
        }
    }

    #[Override]
    protected function rotate(): void
    {
        $this->url = $this->getTimedFilename();
        $this->nextRotation = $this->getNextRotation();
        $this->mustRotate = false;

        if ($this->maxFiles === 0) {
            return;
        }

        try {
            $logFiles = glob($this->getGlobPattern());
        } catch (Throwable) {
            return;
        }

        if ($logFiles === false || $this->maxFiles >= count($logFiles)) {
            return;
        }

        usort($logFiles, static fn (string $left, string $right): int => strcmp($right, $left));

        $basePath = dirname($this->filename);

        foreach (array_slice($logFiles, $this->maxFiles) as $file) {
            if (! is_writable($file)) {
                continue;
            }

            try {
                $deleted = unlink($file);
            } catch (Throwable) {
                $deleted = false;
            }

            if (! $deleted) {
                continue;
            }

            $directory = dirname($file);

            while ($directory !== $basePath) {
                try {
                    $entries = scandir($directory);
                } catch (Throwable) {
                    break;
                }

                if ($entries === false || count(array_diff($entries, ['.', '..'])) > 0) {
                    break;
                }

                try {
                    $removed = rmdir($directory);
                } catch (Throwable) {
                    $removed = false;
                }

                if (! $removed) {
                    break;
                }

                $directory = dirname($directory);
            }
        }
    }
}
