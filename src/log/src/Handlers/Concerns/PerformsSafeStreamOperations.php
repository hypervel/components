<?php

declare(strict_types=1);

namespace Hypervel\Log\Handlers\Concerns;

use LogicException;
use Monolog\LogRecord;
use Monolog\Utils;
use Throwable;
use UnexpectedValueException;

trait PerformsSafeStreamOperations
{
    /** @var null|true */
    private ?bool $safeDirectoryCreated = null;

    private ?int $safeInodeUrl = null;

    /**
     * Close the configured file stream without installing a process-global
     * error handler around a potentially yielding native operation.
     */
    protected function closeStreamSafely(): void
    {
        $stream = $this->stream;
        $this->stream = null;
        $this->safeDirectoryCreated = null;
        $this->safeInodeUrl = null;

        // Streams passed in as resources are owned by their caller, matching
        // Monolog's behavior. Only URL-backed streams are closed here.
        if ($this->url !== null && is_resource($stream)) {
            try {
                fclose($stream);
            } catch (Throwable) {
                // Closing is best-effort and must not abort reset or rotation.
            }
        }
    }

    /**
     * Write a record while preserving Monolog's single reopen retry.
     */
    protected function writeStreamSafely(LogRecord $record, bool $retrying = false): void
    {
        if (! $retrying && $this->hasStreamInodeChanged()) {
            $this->closeStreamSafely();
            $this->writeStreamSafely($record, true);

            return;
        }

        if (! is_resource($this->stream)) {
            $url = $this->url;

            if ($url === null || $url === '') {
                throw new LogicException(
                    'Missing stream url, the stream can not be opened. This may be caused by a premature call to close().'
                    . Utils::getRecordMessageForException($record)
                );
            }

            $this->createStreamDirectory($url);
            try {
                $stream = fopen($url, $this->fileOpenMode);
            } catch (Throwable) {
                $stream = false;
            }

            if (! is_resource($stream)) {
                $this->stream = null;

                throw new UnexpectedValueException(
                    sprintf(
                        'The stream or file "%s" could not be opened using mode "%s".',
                        $url,
                        $this->fileOpenMode
                    ) . Utils::getRecordMessageForException($record)
                );
            }

            if ($this->filePermission !== null) {
                try {
                    chmod($url, $this->filePermission);
                } catch (Throwable) {
                    // File permissions are best-effort, matching Monolog.
                }
            }

            stream_set_chunk_size($stream, $this->streamChunkSize);
            $this->stream = $stream;
            $this->safeInodeUrl = $this->getStreamInode();
        }

        $stream = $this->stream;
        $locked = false;

        if ($this->useLocking) {
            try {
                $locked = flock($stream, LOCK_EX);
            } catch (Throwable) {
                // Locking is best-effort, matching Monolog.
            }
        }

        try {
            try {
                $written = fwrite($stream, (string) $record->formatted);
            } catch (Throwable) {
                $written = false;
            }
        } finally {
            if ($locked) {
                try {
                    flock($stream, LOCK_UN);
                } catch (Throwable) {
                    // Unlocking is best-effort, matching Monolog.
                }
            }
        }

        if ($written !== false) {
            return;
        }

        if (! $retrying && $this->url !== null && $this->url !== 'php://memory') {
            $this->closeStreamSafely();
            $this->writeStreamSafely($record, true);

            return;
        }

        throw new UnexpectedValueException(
            sprintf(
                'Writing to the log %s failed.',
                $this->url === null ? 'stream' : sprintf('file "%s"', $this->url)
            ) . Utils::getRecordMessageForException($record)
        );
    }

    private function createStreamDirectory(string $url): void
    {
        if ($this->safeDirectoryCreated === true) {
            return;
        }

        $directory = $this->directoryFromStream($url);

        if ($directory !== null && ! is_dir($directory)) {
            try {
                $created = mkdir($directory, 0777, true);
            } catch (Throwable) {
                $created = false;
            }

            if (! $created && ! is_dir($directory)) {
                throw new UnexpectedValueException(
                    sprintf('There is no existing directory at "%s" and it could not be created.', $directory)
                );
            }
        }

        $this->safeDirectoryCreated = true;
    }

    private function directoryFromStream(string $stream): ?string
    {
        $schemePosition = strpos($stream, '://');

        if ($schemePosition === false) {
            return dirname($stream);
        }

        return str_starts_with($stream, 'file://')
            ? dirname(substr($stream, 7))
            : null;
    }

    private function getStreamInode(): ?int
    {
        if ($this->url === null || str_starts_with($this->url, 'php://')) {
            return null;
        }

        try {
            $inode = fileinode($this->url);
        } catch (Throwable) {
            return null;
        }

        return $inode === false ? null : $inode;
    }

    private function hasStreamInodeChanged(): bool
    {
        return $this->safeInodeUrl !== null
            && $this->safeInodeUrl !== $this->getStreamInode();
    }
}
