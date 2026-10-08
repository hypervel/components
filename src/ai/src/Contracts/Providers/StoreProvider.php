<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Providers;

use DateInterval;
use Hypervel\Ai\Contracts\Files\HasProviderId;
use Hypervel\Ai\Contracts\Gateway\StoreGateway;
use Hypervel\Ai\Store;
use Hypervel\Support\Collection;

interface StoreProvider extends Provider
{
    /**
     * Get a vector store by its ID.
     */
    public function getStore(string $storeId): Store;

    /**
     * Create a new vector store.
     */
    public function createStore(
        string $name,
        ?string $description = null,
        ?Collection $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): Store;

    /**
     * Add a file to a vector store.
     *
     * @param array<string, mixed> $metadata
     */
    public function addFileToStore(string $storeId, HasProviderId $file, array $metadata = []): string;

    /**
     * Remove a file from a vector store.
     */
    public function removeFileFromStore(string $storeId, HasProviderId|string $fileId): bool;

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(string $storeId): bool;

    /**
     * Get the provider's store gateway.
     */
    public function storeGateway(): StoreGateway;

    /**
     * Set the provider's store gateway.
     */
    public function useStoreGateway(StoreGateway $gateway): self;
}
