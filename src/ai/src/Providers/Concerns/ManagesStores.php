<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use DateInterval;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Contracts\Files\HasProviderId;
use Hypervel\Ai\Events\AddingFileToStore;
use Hypervel\Ai\Events\CreatingStore;
use Hypervel\Ai\Events\FileAddedToStore;
use Hypervel\Ai\Events\FileRemovedFromStore;
use Hypervel\Ai\Events\RemovingFileFromStore;
use Hypervel\Ai\Events\StoreCreated;
use Hypervel\Ai\Events\StoreDeleted;
use Hypervel\Ai\Store;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

trait ManagesStores
{
    /**
     * Get a vector store by its ID.
     */
    public function getStore(string $storeId): Store
    {
        return $this->storeGateway()->getStore($this, $storeId);
    }

    /**
     * Create a new vector store.
     */
    public function createStore(
        string $name,
        ?string $description = null,
        ?Collection $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): Store {
        $invocationId = (string) Str::uuid7();

        $fileIds ??= new Collection;

        if (Ai::storesAreFaked()) {
            Ai::recordStoreCreation($name, $description, $fileIds, $expiresWhenIdleFor);
        }

        if ($this->events->hasListeners(CreatingStore::class)) {
            $this->events->dispatch(new CreatingStore(
                $invocationId,
                $this,
                $name,
                $description,
                $fileIds,
                $expiresWhenIdleFor
            ));
        }

        return tap(
            $this->storeGateway()->createStore($this, $name, $description, $fileIds, $expiresWhenIdleFor),
            function (Store $store) use ($invocationId, $name, $description, $fileIds, $expiresWhenIdleFor): void {
                if ($this->events->hasListeners(StoreCreated::class)) {
                    $this->events->dispatch(new StoreCreated(
                        $invocationId,
                        $this,
                        $name,
                        $description,
                        $fileIds,
                        $expiresWhenIdleFor,
                        $store,
                    ));
                }
            }
        );
    }

    /**
     * Add a file to a vector store.
     */
    public function addFileToStore(string $storeId, HasProviderId $file, array $metadata = []): string
    {
        $invocationId = (string) Str::uuid7();

        if ($this->events->hasListeners(AddingFileToStore::class)) {
            $this->events->dispatch(new AddingFileToStore(
                $invocationId,
                $this,
                $storeId,
                $file->id()
            ));
        }

        return tap(
            $this->storeGateway()->addFile($this, $storeId, $file->id(), $metadata),
            function (string $documentId) use ($invocationId, $storeId, $file): void {
                if ($this->events->hasListeners(FileAddedToStore::class)) {
                    $this->events->dispatch(new FileAddedToStore(
                        $invocationId,
                        $this,
                        $storeId,
                        $file->id(),
                        $documentId,
                    ));
                }
            }
        );
    }

    /**
     * Remove a file from a vector store.
     */
    public function removeFileFromStore(string $storeId, HasProviderId|string $documentId): bool
    {
        $invocationId = (string) Str::uuid7();

        $documentId = $documentId instanceof HasProviderId ? $documentId->id() : $documentId;

        if (Ai::storesAreFaked()) {
            Ai::recordFileRemoval($storeId, $documentId);
        }

        if ($this->events->hasListeners(RemovingFileFromStore::class)) {
            $this->events->dispatch(new RemovingFileFromStore(
                $invocationId,
                $this,
                $storeId,
                $documentId
            ));
        }

        return tap(
            $this->storeGateway()->removeFile($this, $storeId, $documentId),
            function () use ($invocationId, $storeId, $documentId): void {
                if ($this->events->hasListeners(FileRemovedFromStore::class)) {
                    $this->events->dispatch(new FileRemovedFromStore(
                        $invocationId,
                        $this,
                        $storeId,
                        $documentId,
                    ));
                }
            }
        );
    }

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(string $storeId): bool
    {
        $invocationId = (string) Str::uuid7();

        if (Ai::storesAreFaked()) {
            Ai::recordStoreDeletion($storeId);
        }

        $result = $this->storeGateway()->deleteStore($this, $storeId);

        if ($this->events->hasListeners(StoreDeleted::class)) {
            $this->events->dispatch(new StoreDeleted(
                $invocationId,
                $this,
                $storeId,
            ));
        }

        return $result;
    }
}
