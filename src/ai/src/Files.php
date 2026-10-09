<?php

declare(strict_types=1);

namespace Hypervel\Ai;

use Closure;
use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\LocalDocument;
use Hypervel\Ai\Gateway\FakeFileGateway;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\FileResponse;
use Hypervel\Ai\Responses\StoredFileResponse;
use Hypervel\Http\UploadedFile;

class Files
{
    /**
     * Get a file by its ID.
     */
    public static function get(string $fileId, Provider|string|null $provider = null): FileResponse
    {
        return Ai::fakeableFileProvider($provider)->getFile($fileId);
    }

    /**
     * Store the given file.
     */
    public static function put(
        StorableFile|UploadedFile|string $file,
        ?string $mimeType = null,
        ?string $name = null,
        Provider|string|null $provider = null
    ): StoredFileResponse {
        $file = match (true) {
            is_string($file) => new Base64Document(base64_encode($file), $mimeType),
            $file instanceof UploadedFile => LocalDocument::fromUploadedFile($file),
            default => $file,
        };

        if ($name !== null) {
            $file = $file->as($name);
        }

        if ($mimeType !== null) {
            $file = $file->withMimeType($mimeType);
        }

        return Ai::fakeableFileProvider($provider)->putFile($file);
    }

    /**
     * Store the file at the given local path.
     */
    public static function putFromPath(string $path, ?string $mimeType = null, ?string $name = null, Provider|string|null $provider = null): StoredFileResponse
    {
        return static::put(Document::fromPath($path), $mimeType, $name, provider: $provider);
    }

    /**
     * Store the file at the given path on the given disk.
     */
    public static function putFromStorage(string $path, ?string $disk = null, ?string $name = null, Provider|string|null $provider = null): StoredFileResponse
    {
        return static::put(Document::fromStorage($path, $disk), name: $name, provider: $provider);
    }

    /**
     * Delete a file by its ID.
     */
    public static function delete(string $fileId, Provider|string|null $provider = null): void
    {
        Ai::fakeableFileProvider($provider)->deleteFile($fileId);
    }

    /**
     * Fake file operations.
     *
     * Tests only. The fake gateway is shared across requests in the worker.
     */
    public static function fake(Closure|array $responses = []): FakeFileGateway
    {
        return Ai::fakeFiles($responses);
    }

    /**
     * Get the fake file ID for a given name or content.
     */
    public static function fakeId(string $for): string
    {
        return 'fake_file_' . md5($for);
    }

    /**
     * Assert that a file was stored matching a given truth test.
     */
    public static function assertStored(Closure $callback): void
    {
        Ai::assertFileUploaded($callback);
    }

    /**
     * Assert that a file was not stored matching a given truth test.
     */
    public static function assertNotStored(Closure $callback): void
    {
        Ai::assertFileNotUploaded($callback);
    }

    /**
     * Assert that no files were stored.
     */
    public static function assertNothingStored(): void
    {
        Ai::assertNoFilesUploaded();
    }

    /**
     * Assert that a file was deleted matching a given truth test.
     */
    public static function assertDeleted(Closure|string $callback): void
    {
        Ai::assertFileDeleted($callback);
    }

    /**
     * Assert that a file was not deleted matching a given truth test.
     */
    public static function assertNotDeleted(Closure|string $callback): void
    {
        Ai::assertFileNotDeleted($callback);
    }

    /**
     * Assert that no files were deleted.
     */
    public static function assertNothingDeleted(): void
    {
        Ai::assertNoFilesDeleted();
    }

    /**
     * Determine if file operations are faked.
     */
    public static function isFaked(): bool
    {
        return Ai::filesAreFaked();
    }
}
