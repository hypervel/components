<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Events\FileDeleted;
use Hypervel\Ai\Events\FileStored;
use Hypervel\Ai\Events\StoringFile;
use Hypervel\Ai\Responses\FileResponse;
use Hypervel\Ai\Responses\StoredFileResponse;
use Hypervel\Support\Str;

trait ManagesFiles
{
    /**
     * Get a file by its ID.
     */
    public function getFile(string $fileId): FileResponse
    {
        return $this->fileGateway()->getFile($this, $fileId);
    }

    /**
     * Store the given file.
     */
    public function putFile(StorableFile $file): StoredFileResponse
    {
        $invocationId = (string) Str::uuid7();

        if (Ai::filesAreFaked()) {
            Ai::recordFileUpload($file);
        }

        if ($this->events->hasListeners(StoringFile::class)) {
            $this->events->dispatch(new StoringFile(
                $invocationId,
                $this,
                $file
            ));
        }

        return tap(
            $this->fileGateway()->putFile($this, $file),
            function (StoredFileResponse $response) use ($invocationId, $file): void {
                if ($this->events->hasListeners(FileStored::class)) {
                    $this->events->dispatch(new FileStored(
                        $invocationId,
                        $this,
                        $file,
                        $response,
                    ));
                }
            }
        );
    }

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(string $fileId): void
    {
        $invocationId = (string) Str::uuid7();

        if (Ai::filesAreFaked()) {
            Ai::recordFileDeletion($fileId);
        }

        $this->fileGateway()->deleteFile($this, $fileId);

        if ($this->events->hasListeners(FileDeleted::class)) {
            $this->events->dispatch(new FileDeleted(
                $invocationId,
                $this,
                $fileId,
            ));
        }
    }
}
