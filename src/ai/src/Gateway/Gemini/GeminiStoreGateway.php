<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Gemini;

use DateInterval;
use Hypervel\Ai\Contracts\Gateway\ListsStoreFiles;
use Hypervel\Ai\Contracts\Gateway\StoreGateway;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Contracts\Providers\StoreProvider;
use Hypervel\Ai\Enums\StoreDocumentStatus;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Gateway\Concerns\CreatesClient;
use Hypervel\Ai\Gateway\Concerns\EncodesResourceIds;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\StoreDocument;
use Hypervel\Ai\Responses\Data\StoreFileCounts;
use Hypervel\Ai\Store;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Http\Client\Response;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;
use Hypervel\Support\Sleep;
use Hypervel\Support\Str;

class GeminiStoreGateway implements StoreGateway, ListsStoreFiles
{
    use Concerns\HandlesGeminiErrors;
    use CreatesClient;
    use EncodesResourceIds;

    /**
     * Get a vector store by its ID.
     */
    public function getStore(StoreProvider $provider, string $storeId): Store
    {
        /** @var FileProvider&Provider&StoreProvider $provider */
        $storeId = $this->normalizeStoreId($storeId);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->get($this->baseUrl($provider) . "/{$storeId}")->throw(),
        );

        return new Store(
            provider: $provider,
            id: $response->json('name'),
            name: $response->json('displayName'),
            fileCounts: new StoreFileCounts(
                completed: (int) $response->json('activeDocumentsCount', 0),
                pending: (int) $response->json('pendingDocumentsCount', 0),
                failed: (int) $response->json('failedDocumentsCount', 0),
            ),
            ready: true,
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

        $response = $this->withErrorHandling($provider->name(), fn () => $this->client($provider)->post($this->baseUrl($provider) . '/fileSearchStores', [
            'displayName' => $name,
        ])->throw());

        $store = $this->getStore($provider, $response->json('name'));

        if ($fileIds->isNotEmpty()) {
            foreach ($fileIds as $fileId) {
                $this->addFile($provider, $store->id, $fileId);
            }
        }

        return $store;
    }

    /**
     * Add a file to a vector store.
     */
    public function addFile(StoreProvider $provider, string $storeId, string $fileId, array $metadata = []): string
    {
        /** @var Provider&StoreProvider $provider */
        $storeId = $this->normalizeStoreId($storeId);
        $fileId = $this->normalizeFileId($fileId);

        $response = $this->withErrorHandling($provider->name(), fn () => $this->client($provider)->post($this->baseUrl($provider) . "/{$storeId}:importFile", array_filter([
            'fileName' => $fileId,
            'customMetadata' => $metadata === [] ? null : $this->formatMetadata($metadata),
        ]))->throw());

        $operation = $this->waitForImportOperation($provider, $response);

        if ($operation->json('error') !== null) {
            throw new AiException(sprintf(
                'Gemini Error: [%s] %s',
                $operation->json('error.code', 'unknown'),
                $operation->json('error.message', 'Unknown Gemini error.'),
            ));
        }

        $documentName = $operation->json('response.documentName');

        if (! is_string($documentName) || $documentName === '') {
            throw new AiException('Gemini Error: [invalid_response] File import completed without a document name.');
        }

        return basename($documentName);
    }

    /**
     * Wait for a Gemini file import operation to complete.
     */
    protected function waitForImportOperation(StoreProvider $provider, Response $operation): Response
    {
        /** @var Provider&StoreProvider $provider */
        $operationName = $operation->json('name');

        for ($attempt = 0; ! $operation->json('done', false); ++$attempt) {
            if ($attempt >= 60) {
                throw new AiException('Gemini Error: [timeout] File import operation did not complete.');
            }

            ConnectionResolver::releaseIdleConnections();

            Sleep::for(5)->seconds();

            $operation = $this->withErrorHandling(
                $provider->name(),
                fn () => $this->client($provider)->get($this->baseUrl($provider) . "/{$operationName}")->throw(),
            );
        }

        return $operation;
    }

    /**
     * Format metadata for Gemini's custom_metadata format.
     */
    protected function formatMetadata(array $metadata): array
    {
        return (new Collection($metadata))->map(fn (mixed $value, int|string $key): array => match (true) {
            is_numeric($value) => ['key' => (string) $key, 'numericValue' => $value],
            is_array($value) => ['key' => (string) $key, 'stringListValue' => ['values' => $value]],
            default => ['key' => (string) $key, 'stringValue' => (string) $value],
        })->values()->all();
    }

    /**
     * List the documents in a vector store, without file IDs, which Gemini does not return.
     *
     * @return LazyCollection<int, StoreDocument>
     */
    public function listFiles(StoreProvider $provider, string $storeId): LazyCollection
    {
        /** @var Provider&StoreProvider $provider */
        return LazyCollection::make(function () use ($provider, $storeId) {
            $storeId = $this->normalizeStoreId($storeId);
            $pageToken = null;

            do {
                $response = $this->withErrorHandling($provider->name(), fn () => $this->client($provider)
                    ->get($this->baseUrl($provider) . "/{$storeId}/documents", Arr::whereNotNull([
                        'pageSize' => 20,
                        'pageToken' => $pageToken,
                    ]))->throw());

                foreach ($response->json('documents') ?? [] as $document) {
                    yield new StoreDocument(
                        id: basename($document['name']),
                        status: $this->documentStatus($document['state'] ?? null),
                        metadata: $this->parseMetadata($document['customMetadata'] ?? []),
                    );
                }

                $pageToken = $response->json('nextPageToken');
            } while (filled($pageToken));
        });
    }

    /**
     * Map a Gemini document state to a document status.
     */
    protected function documentStatus(?string $state): ?StoreDocumentStatus
    {
        return match ($state) {
            'STATE_ACTIVE' => StoreDocumentStatus::Completed,
            'STATE_PENDING' => StoreDocumentStatus::Pending,
            'STATE_FAILED' => StoreDocumentStatus::Failed,
            default => null,
        };
    }

    /**
     * Parse metadata from Gemini's custom_metadata format.
     *
     * @return array<string, mixed>
     */
    protected function parseMetadata(array $metadata): array
    {
        return (new Collection($metadata))->mapWithKeys(fn (array $entry): array => [$entry['key'] => match (true) {
            isset($entry['numericValue']) => $entry['numericValue'],
            isset($entry['stringListValue']) => $entry['stringListValue']['values'] ?? [],
            default => $entry['stringValue'] ?? null,
        }])->all();
    }

    /**
     * Remove a file from a vector store.
     */
    public function removeFile(StoreProvider $provider, string $storeId, string $documentId): bool
    {
        /** @var Provider&StoreProvider $provider */
        $storeId = $this->normalizeStoreId($storeId);
        $documentId = $this->normalizeDocumentId($storeId, $documentId);

        // Gemini only accepts force as a query parameter, and refuses to delete a document that still has chunks without it...
        $this->withErrorHandling($provider->name(), fn () => $this->client($provider)
            ->delete($this->baseUrl($provider) . "/{$documentId}?force=true")->throw());

        return true;
    }

    /**
     * Delete a vector store by its ID.
     */
    public function deleteStore(StoreProvider $provider, string $storeId): bool
    {
        /** @var Provider&StoreProvider $provider */
        $storeId = $this->normalizeStoreId($storeId);

        $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider)->delete($this->baseUrl($provider) . "/{$storeId}?force=true")->throw(),
        );

        return true;
    }

    /**
     * Get an HTTP client for the Gemini File Search API.
     */
    protected function client(Provider $provider): PendingRequest
    {
        return $this->createClient(
            $this->baseUrl($provider),
            array_filter(['x-goog-api-key' => $provider->providerCredentials()['key']]),
            $provider->additionalConfiguration()['headers'] ?? [],
            timeout: null,
            throw: false,
        )->connection($this->httpConnection($provider));
    }

    /**
     * Get the base URL for the Gemini API.
     */
    protected function baseUrl(Provider $provider): string
    {
        return rtrim($provider->additionalConfiguration()['url'] ?? 'https://generativelanguage.googleapis.com/v1beta', '/');
    }

    /**
     * Normalize the store ID to include the resource prefix.
     */
    protected function normalizeStoreId(string $storeId): string
    {
        return 'fileSearchStores/' . $this->resourceId(Str::chopStart($storeId, 'fileSearchStores/'));
    }

    /**
     * Normalize the file ID to include the resource prefix.
     */
    protected function normalizeFileId(string $fileId): string
    {
        return 'files/' . $this->resourceId(Str::chopStart($fileId, 'files/'));
    }

    /**
     * Normalize the document ID to include the full resource path.
     */
    protected function normalizeDocumentId(string $storeId, string $documentId): string
    {
        return "{$storeId}/documents/" . $this->resourceId(Str::chopStart($documentId, ["{$storeId}/documents/", 'documents/', 'files/']));
    }
}
