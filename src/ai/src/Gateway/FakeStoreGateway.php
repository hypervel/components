<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Closure;
use DateInterval;
use Hypervel\Ai\Contracts\Gateway\StoreGateway;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Contracts\Providers\StoreProvider;
use Hypervel\Ai\Responses\Data\StoreFileCounts;
use Hypervel\Ai\Store;
use Hypervel\Ai\Stores;
use Hypervel\Support\Collection;
use RuntimeException;

class FakeStoreGateway implements StoreGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayOperations = false;

    /**
     * Create a store gateway with fake responses.
     */
    public function __construct(
        protected Closure|array $responses = [],
    ) {
    }

    /**
     * Get a vector store by its ID.
     */
    public function getStore(StoreProvider $provider, string $storeId): Store
    {
        return $this->nextGetResponse($provider, $storeId);
    }

    /**
     * Get the next response for a get request.
     */
    protected function nextGetResponse(StoreProvider $provider, string $storeId): Store
    {
        // Reserve the entry first; callbacks may yield or throw.
        $index = $this->currentResponseIndex++;

        $response = is_array($this->responses)
            ? ($this->responses[$index] ?? null)
            : call_user_func($this->responses, $storeId);

        return $this->marshalGetResponse(
            $provider,
            $response,
            $storeId
        );
    }

    /**
     * Marshal the given response into a Store instance.
     */
    protected function marshalGetResponse(StoreProvider $provider, mixed $response, string $storeId): Store
    {
        if ($response instanceof Closure) {
            $response = $response($storeId);
        }

        if (is_null($response)) {
            if ($this->preventStrayOperations) {
                throw new RuntimeException('Attempted store retrieval without a fake response.');
            }

            /** @var FileProvider&StoreProvider $provider */
            return new Store(
                provider: $provider,
                id: $storeId,
                name: 'fake-store',
                fileCounts: new StoreFileCounts(0, 0, 0),
                ready: true,
            );
        }

        if (is_string($response)) {
            /** @var FileProvider&StoreProvider $provider */
            return new Store(
                provider: $provider,
                id: $storeId,
                name: $response,
                fileCounts: new StoreFileCounts(0, 0, 0),
                ready: true,
            );
        }

        return $response;
    }

    /**
     * Create a new vector store.
     */
    public function createStore(
        StoreProvider $provider,
        string $name,
        ?string $description = null,
        ?Collection $fileIds = null,
        ?DateInterval $expiresWhenIdleFor = null,
    ): Store {
        /** @var FileProvider&StoreProvider $provider */
        return new Store(
            provider: $provider,
            id: Stores::fakeId($name),
            name: $name,
            fileCounts: new StoreFileCounts(0, 0, 0),
            ready: true,
        );
    }

    /**
     * Add a file to a vector store.
     */
    public function addFile(StoreProvider $provider, string $storeId, string $fileId, array $metadata = []): string
    {
        return $fileId;
    }

    /**
     * Remove a file from a vector store.
     */
    public function removeFile(StoreProvider $provider, string $storeId, string $documentId): bool
    {
        return true;
    }

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(StoreProvider $provider, string $storeId): bool
    {
        return true;
    }

    /**
     * Indicate that an exception should be thrown if any store operation is not faked.
     *
     * Tests only. This setting affects the fake gateway shared by requests in the worker.
     */
    public function preventStrayOperations(bool $prevent = true): self
    {
        $this->preventStrayOperations = $prevent;

        return $this;
    }
}
