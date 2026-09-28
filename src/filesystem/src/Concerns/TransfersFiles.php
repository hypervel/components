<?php

declare(strict_types=1);

namespace Hypervel\Filesystem\Concerns;

use Hypervel\Container\Container;
use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use Hypervel\Contracts\Filesystem\Filesystem as FilesystemContract;
use Hypervel\Filesystem\LeasedStream;
use InvalidArgumentException;
use League\Flysystem\UnableToReadFile;
use UnitEnum;

trait TransfersFiles
{
    /**
     * Copy a file to another disk.
     */
    public function copyToDisk(FilesystemContract|string|UnitEnum $disk, string $from, ?string $to = null): bool
    {
        $destination = $disk instanceof FilesystemContract
            ? $disk
            : Container::getInstance()->make(FilesystemFactory::class)->disk($disk);

        if ($destination === $this && ($to ?? $from) === $from) {
            throw new InvalidArgumentException('Cannot copy a file to the same disk and path.');
        }

        return $this->transferFile($this, $from, $destination, $to ?? $from);
    }

    /**
     * Move a file to another disk.
     */
    public function moveToDisk(FilesystemContract|string|UnitEnum $disk, string $from, ?string $to = null): bool
    {
        return $this->copyToDisk($disk, $from, $to) && $this->delete($from);
    }

    /**
     * Transfer a file without holding a source lease during the destination write.
     */
    protected function transferFile(FilesystemContract $source, string $from, FilesystemContract $destination, string $to): bool
    {
        $stream = $source->readStream($from);

        if (! is_resource($stream)) {
            return false;
        }

        $temporary = null;

        try {
            if ((stream_get_meta_data($stream)['wrapper_data'] ?? null) instanceof LeasedStream) {
                // The destination may borrow from the same bounded pool as the source.
                $temporary = fopen('php://temp', 'w+b');

                if ($temporary === false || stream_copy_to_stream($stream, $temporary) === false || ! rewind($temporary)) {
                    throw UnableToReadFile::fromLocation($from);
                }

                fclose($stream);
            }

            return $destination->writeStream($to, $temporary ?? $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (is_resource($temporary)) {
                fclose($temporary);
            }
        }
    }
}
