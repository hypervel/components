<?php

declare(strict_types=1);

namespace Hypervel\Ai;

use Closure;
use Hypervel\Ai\Contracts\Files\HasProviderId;
use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Contracts\Providers\StoreProvider;
use Hypervel\Ai\Files\LocalDocument;
use Hypervel\Ai\Files\ProviderDocument;
use Hypervel\Ai\Responses\AddedDocumentResponse;
use Hypervel\Ai\Responses\Data\StoreFileCounts;
use Hypervel\Http\UploadedFile;

class Store
{
    /**
     * Create a new store instance.
     */
    public function __construct(
        protected FileProvider&StoreProvider $provider,
        public readonly string $id,
        public readonly ?string $name,
        public readonly StoreFileCounts $fileCounts,
        public readonly bool $ready,
    ) {
    }

    /**
     * Add a file to the store.
     */
    public function add(
        StorableFile|UploadedFile|HasProviderId|string $file,
        array $metadata = [],
    ): AddedDocumentResponse {
        if ($file instanceof UploadedFile) {
            $file = LocalDocument::fromUploadedFile($file);
        }

        $originalFile = $file;

        if ($file instanceof StorableFile) {
            $file = $this->storeFile($file);
        }

        if (Ai::storesAreFaked()) {
            Ai::recordFileAddition($this->id, $file instanceof HasProviderId ? $file->id() : $file, $originalFile);
        }

        return new AddedDocumentResponse($this->provider->addFileToStore($this->id, match (true) {
            is_string($file) => new ProviderDocument($file),
            default => $file,
        }, $metadata), match (true) {
            $file instanceof HasProviderId => $file->id(),
            default => $file,
        });
    }

    /**
     * Resolve the ID of the file a document was imported from, which Gemini does not reuse as the document ID.
     */
    protected function fileIdFor(HasProviderId|string $documentId): string
    {
        if ($documentId instanceof AddedDocumentResponse && $documentId->fileId() !== null) {
            return $documentId->fileId();
        }

        return $documentId instanceof HasProviderId ? $documentId->id() : $documentId;
    }

    /**
     * Store the given file with the provider.
     */
    protected function storeFile(StorableFile $file): HasProviderId
    {
        return $this->fakeableFileProvider()->putFile($file);
    }

    /**
     * Get the file provider, using a fake gateway if files are faked.
     */
    protected function fakeableFileProvider(): FileProvider
    {
        // Resolving the captured provider by name again can select different credentials.
        return Ai::filesAreFaked()
            ? (clone $this->provider)->useFileGateway(Ai::fakeFileGateway())
            : $this->provider;
    }

    /**
     * Remove a document from the store.
     */
    public function remove(HasProviderId|string $documentId, bool $deleteFile = false): bool
    {
        $removed = $this->provider->removeFileFromStore($this->id, $documentId);

        if ($deleteFile && $removed) {
            $this->fakeableFileProvider()->deleteFile($this->fileIdFor($documentId));
        }

        return $removed;
    }

    /**
     * Refresh the store from the provider.
     */
    public function refresh(): self
    {
        return $this->provider->getStore($this->id);
    }

    /**
     * Delete the store from the provider.
     */
    public function delete(): bool
    {
        return $this->provider->deleteStore($this->id);
    }

    /**
     * Assert that a file was added to the store.
     */
    public function assertAdded(Closure|string $fileId): self
    {
        Ai::assertFileAddedToStore($this->fileAssertionCallback($fileId));

        return $this;
    }

    /**
     * Assert that a file was not added to the store.
     */
    public function assertNotAdded(Closure|string $fileId): self
    {
        Ai::assertFileNotAddedToStore($this->fileAssertionCallback($fileId));

        return $this;
    }

    /**
     * Assert that a document was removed from the store.
     */
    public function assertRemoved(Closure|string $documentId): self
    {
        Ai::assertFileRemovedFromStore($this->fileAssertionCallback($documentId));

        return $this;
    }

    /**
     * Assert that a document was not removed from the store.
     */
    public function assertNotRemoved(Closure|string $documentId): self
    {
        Ai::assertFileNotRemovedFromStore($this->fileAssertionCallback($documentId));

        return $this;
    }

    /**
     * Get a callback for matching file assertions on this store.
     */
    protected function fileAssertionCallback(Closure|string $fileId): Closure
    {
        if ($fileId instanceof Closure) {
            return fn (string $storeId, StorableFile|HasProviderId|string $file): bool => $storeId === $this->id && $fileId($file);
        }

        $expectedFileId = str_starts_with($fileId, 'fake_file_') ? $fileId : Files::fakeId($fileId);

        return fn (string $storeId, StorableFile|HasProviderId|string $file): bool => $storeId === $this->id && $this->fileIdMatches($file, $expectedFileId);
    }

    /**
     * Determine if the given file matches the expected file ID.
     */
    protected function fileIdMatches(
        StorableFile|HasProviderId|string $file,
        string $expectedFileId,
    ): bool {
        return match (true) {
            $file instanceof HasProviderId => $file->id() === $expectedFileId,
            is_string($file) => $file === $expectedFileId,
            default => false,
        };
    }
}
