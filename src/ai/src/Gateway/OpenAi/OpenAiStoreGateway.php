<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenAi;

use DateInterval;
use Hypervel\Ai\Contracts\Gateway\ListsStoreFiles;
use Hypervel\Ai\Contracts\Gateway\StoreGateway;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Contracts\Providers\StoreProvider;
use Hypervel\Ai\Enums\StoreDocumentStatus;
use Hypervel\Ai\Gateway\Concerns\EncodesResourceIds;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\OpenAi\Concerns\CreatesOpenAiClient;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\StoreDocument;
use Hypervel\Ai\Responses\Data\StoreFileCounts;
use Hypervel\Ai\Store;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\LazyCollection;

class OpenAiStoreGateway implements StoreGateway, ListsStoreFiles
{
    use CreatesOpenAiClient;
    use EncodesResourceIds;
    use HandlesFailoverErrors;

    /**
     * Get a vector store by its ID.
     */
    public function getStore(StoreProvider $provider, string $storeId): Store
    {
        /** @var FileProvider&Provider&StoreProvider $provider */
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->get("vector_stores/{$this->resourceId($storeId)}")
        );

        return new Store(
            provider: $provider,
            id: $response->json('id'),
            name: $response->json('name'),
            fileCounts: new StoreFileCounts(
                completed: $response->json('file_counts.completed'),
                pending: $response->json('file_counts.in_progress'),
                failed: $response->json('file_counts.failed'),
            ),
            ready: $response->json('status') === 'completed',
        );
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
        /** @var Provider&StoreProvider $provider */
        $fileIds ??= new Collection;

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->post('vector_stores', [
                    'name' => $name,
                    ...array_filter([
                        'metadata' => filled($description) ? ['description' => $description] : null,
                        'file_ids' => $fileIds->values()->all(),
                        'expires_after' => $expiresWhenIdleFor instanceof DateInterval ? [
                            'anchor' => 'last_active_at',
                            'days' => $this->intervalToDays($expiresWhenIdleFor),
                        ] : null,
                    ]),
                ])
        );

        return $this->getStore($provider, $response->json('id'));
    }

    /**
     * Add a file to a vector store.
     */
    public function addFile(StoreProvider $provider, string $storeId, string $fileId, array $metadata = []): string
    {
        /** @var Provider&StoreProvider $provider */
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->post("vector_stores/{$this->resourceId($storeId)}/files", array_filter([
                    'file_id' => $fileId,
                    'attributes' => filled($metadata) ? (object) $metadata : null,
                ]))
        );

        return $response->json('id');
    }

    /**
     * List the documents in a vector store, fetching further pages as they are needed.
     *
     * @return LazyCollection<int, StoreDocument>
     */
    public function listFiles(StoreProvider $provider, string $storeId): LazyCollection
    {
        /** @var Provider&StoreProvider $provider */
        return LazyCollection::make(function () use ($provider, $storeId) {
            $after = null;

            do {
                $response = $this->withErrorHandling(
                    $provider->name(),
                    fn () => $this->client($provider)
                        ->get("vector_stores/{$this->resourceId($storeId)}/files", Arr::whereNotNull([
                            'limit' => 100,
                            'after' => $after,
                        ]))
                );

                foreach ($response->json('data') ?? [] as $file) {
                    yield new StoreDocument(
                        id: $file['id'],
                        fileId: $file['id'],
                        status: $this->documentStatus($file['status'] ?? null),
                        metadata: $file['attributes'] ?? [],
                    );
                }

                $after = $response->json('last_id');
            } while ($response->json('has_more') === true && filled($after));
        });
    }

    /**
     * Map an OpenAI vector store file status to a document status.
     */
    protected function documentStatus(?string $status): ?StoreDocumentStatus
    {
        return match ($status) {
            'completed' => StoreDocumentStatus::Completed,
            'in_progress' => StoreDocumentStatus::Pending,
            'failed', 'cancelled' => StoreDocumentStatus::Failed,
            default => null,
        };
    }

    /**
     * Remove a file from a vector store.
     */
    public function removeFile(StoreProvider $provider, string $storeId, string $documentId): bool
    {
        /** @var Provider&StoreProvider $provider */
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->delete("vector_stores/{$this->resourceId($storeId)}/files/{$this->resourceId($documentId)}")
        );

        return $response->json('deleted', false);
    }

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(StoreProvider $provider, string $storeId): bool
    {
        /** @var Provider&StoreProvider $provider */
        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)
                ->delete("vector_stores/{$this->resourceId($storeId)}")
        );

        return $response->json('deleted', false);
    }

    /**
     * Convert a DateInterval to days.
     */
    protected function intervalToDays(DateInterval $interval): int
    {
        return max(1, (int) Date::now()->diff(Date::now()->add($interval))->days);
    }
}
