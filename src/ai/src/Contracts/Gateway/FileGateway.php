<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Gateway;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Responses\FileResponse;
use Hypervel\Ai\Responses\StoredFileResponse;

interface FileGateway
{
    /**
     * Get a file by its ID.
     */
    public function getFile(
        FileProvider $provider,
        string $fileId,
    ): FileResponse;

    /**
     * Store the given file.
     */
    public function putFile(
        FileProvider $provider,
        StorableFile $file,
    ): StoredFileResponse;

    /**
     * Delete a file by its ID.
     */
    public function deleteFile(
        FileProvider $provider,
        string $fileId,
    ): void;
}
