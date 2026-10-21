<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Ai\AiManager;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Providers\GeminiProvider;
use Hypervel\Ai\Stores;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Sleep;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\sentRequest;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

/**
 * Get the configured Gemini provider.
 */
function geminiProvider(): GeminiProvider
{
    return app(AiManager::class)->instance('gemini');
}

/**
 * Create a file search store response.
 */
function fakeStoreResponse(string $name = 'fileSearchStores/store123', string $displayName = 'Test Store'): array
{
    return [
        'name' => $name,
        'displayName' => $displayName,
        'activeDocumentsCount' => '5',
        'pendingDocumentsCount' => '1',
        'failedDocumentsCount' => '0',
    ];
}

class StoreGatewayTest extends TestCase
{
    /**
     * Configure the test environment.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('ai.providers.gemini.key', 'test-gemini-key');
    }

    public function testGetStoreSendsCorrectRequest(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(fakeStoreResponse()),
        ]);

        $store = Stores::get('store123', provider: 'gemini');

        $this->assertSame('fileSearchStores/store123', $store->id);
        $this->assertSame('Test Store', $store->name);
        $this->assertSame(5, $store->fileCounts->completed);
        $this->assertSame(1, $store->fileCounts->pending);
        $this->assertSame(0, $store->fileCounts->failed);
        $this->assertTrue($store->ready);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains((string) $request->url(), 'v1beta/fileSearchStores/store123')
            && $request->hasHeader('x-goog-api-key', 'test-gemini-key'));
    }

    public function testGetStoreNormalizesIdWithPrefix(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(fakeStoreResponse()),
        ]);

        Stores::get('fileSearchStores/store123', provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request->url(), 'v1beta/fileSearchStores/store123')
            && ! str_contains((string) $request->url(), 'fileSearchStores/fileSearchStores/'));
    }

    public function testCreateStoreSendsCorrectRequest(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(fakeStoreResponse()),
        ]);

        $store = Stores::create('Test Store', provider: 'gemini');

        $this->assertSame('fileSearchStores/store123', $store->id);
        $this->assertSame('Test Store', $store->name);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains((string) $request->url(), 'v1beta/fileSearchStores')
            && ($request->data()['displayName'] ?? null) === 'Test Store');
    }

    public function testCreateStoreWithFileIdsAddsFiles(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response(['name' => 'fileSearchStores/store123']),
                Http::response(fakeStoreResponse()),
                Http::response([
                    'name' => 'fileSearchStores/store123/operations/import1',
                    'done' => true,
                    'response' => ['documentName' => 'fileSearchStores/store123/documents/doc1'],
                ]),
                Http::response([
                    'name' => 'fileSearchStores/store123/operations/import2',
                    'done' => true,
                    'response' => ['documentName' => 'fileSearchStores/store123/documents/doc2'],
                ]),
            ]),
        ]);

        Stores::create('Test Store', fileIds: ['files/file1', 'files/file2'], provider: 'gemini');

        Http::assertSentCount(4);
    }

    public function testAddFileSendsCorrectRequest(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'name' => 'fileSearchStores/store123/operations/import456',
                'done' => true,
                'response' => [
                    'documentName' => 'fileSearchStores/store123/documents/doc456',
                ],
            ]),
        ]);

        $provider = geminiProvider();
        $documentId = $provider->storeGateway()->addFile($provider, 'store123', 'file789');

        $this->assertSame('doc456', $documentId);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains((string) $request->url(), 'fileSearchStores/store123:importFile')
            && ($request->data()['fileName'] ?? null) === 'files/file789');
    }

    public function testAddFileWaitsForTheImportOperationAndReturnsTheDocumentId(): void
    {
        Sleep::fake();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence([
                Http::response([
                    'name' => 'fileSearchStores/store123/operations/import456',
                    'done' => false,
                ]),
                Http::response([
                    'name' => 'fileSearchStores/store123/operations/import456',
                    'done' => true,
                    'response' => [
                        'documentName' => 'fileSearchStores/store123/documents/doc456',
                    ],
                ]),
            ]),
        ]);

        $provider = geminiProvider();
        $documentId = $provider->storeGateway()->addFile($provider, 'store123', 'file789');

        $this->assertSame('doc456', $documentId);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains((string) $request->url(), 'fileSearchStores/store123/operations/import456'));
        Sleep::assertSequence([Sleep::for(5)->seconds()]);
    }

    public function testAddFileReportsAnImportOperationError(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'name' => 'fileSearchStores/store123/operations/import456',
                'done' => true,
                'error' => [
                    'code' => 13,
                    'message' => 'Indexing failed.',
                ],
            ]),
        ]);

        $provider = geminiProvider();

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Gemini Error: [13] Indexing failed.');
        $provider->storeGateway()->addFile($provider, 'store123', 'file789');
    }

    public function testAddFileRejectsACompletedImportWithoutADocumentName(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'name' => 'fileSearchStores/store123/operations/import456',
                'done' => true,
                'response' => [],
            ]),
        ]);

        $provider = geminiProvider();

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('Gemini Error: [invalid_response] File import completed without a document name.');
        $provider->storeGateway()->addFile($provider, 'store123', 'file789');
    }

    public function testAddFileTimesOutWhenTheImportOperationNeverCompletes(): void
    {
        Sleep::fake();

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'name' => 'fileSearchStores/store123/operations/import456',
                'done' => false,
            ]),
        ]);

        $provider = geminiProvider();

        try {
            $provider->storeGateway()->addFile($provider, 'store123', 'file789');
            $this->fail('Expected the import operation to time out.');
        } catch (AiException $exception) {
            $this->assertSame('Gemini Error: [timeout] File import operation did not complete.', $exception->getMessage());
        }

        Http::assertSentCount(61);
    }

    public function testAddFileWithMetadataFormatsCorrectly(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'name' => 'fileSearchStores/store123/operations/import456',
                'done' => true,
                'response' => [
                    'documentName' => 'fileSearchStores/store123/documents/doc456',
                ],
            ]),
        ]);

        $provider = geminiProvider();
        $provider->storeGateway()->addFile($provider, 'store123', 'file789', [
            'company' => 'hypervel',
            'priority' => 5,
            'tags' => ['php', 'framework'],
            '0' => 'numeric key',
        ]);

        Http::assertSent(function (Request $request): bool {
            $metadata = $request->data()['customMetadata'] ?? [];

            $string = collect($metadata)->firstWhere('key', 'company');
            $numeric = collect($metadata)->firstWhere('key', 'priority');
            $list = collect($metadata)->firstWhere('key', 'tags');
            $numericKey = collect($metadata)->first(fn (array $item): bool => $item['key'] === '0');

            return $string['stringValue'] === 'hypervel'
                && $numeric['numericValue'] === 5
                && $list['stringListValue']['values'] === ['php', 'framework']
                && $numericKey !== null
                && $numericKey['stringValue'] === 'numeric key';
        });
    }

    public function testRemoveFileSendsCorrectRequest(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $provider = geminiProvider();
        $result = $provider->storeGateway()->removeFile($provider, 'store123', 'doc456');

        $this->assertTrue($result);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), 'fileSearchStores/store123/documents/doc456'));
    }

    public function testRemoveFileNormalizesDocumentIdWithFilesPrefix(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $provider = geminiProvider();
        $provider->storeGateway()->removeFile($provider, 'store123', 'files/doc456');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request->url(), 'fileSearchStores/store123/documents/doc456'));
    }

    public function testRemoveFileNormalizesDocumentIdWithDocumentsPrefix(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $provider = geminiProvider();
        $provider->storeGateway()->removeFile($provider, 'store123', 'documents/doc456');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request->url(), 'fileSearchStores/store123/documents/doc456'));
    }

    public function testRemoveFileAcceptsFullDocumentPath(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $provider = geminiProvider();
        $provider->storeGateway()->removeFile($provider, 'store123', 'fileSearchStores/store123/documents/doc456');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request->url(), 'fileSearchStores/store123/documents/doc456')
            && ! str_contains((string) $request->url(), 'fileSearchStores/store123/documents/fileSearchStores/'));
    }

    public function testDeleteStoreSendsCorrectRequest(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $result = Stores::delete('store123', provider: 'gemini');

        $this->assertTrue($result);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), 'v1beta/fileSearchStores/store123')
            && $request->hasHeader('x-goog-api-key', 'test-gemini-key'));
    }

    public function testStoreGatewayUsesCustomBaseUrl(): void
    {
        config(['ai.providers.gemini' => [
            ...config('ai.providers.gemini'),
            'key' => 'test-gemini-key',
            'url' => 'https://custom.api.example.com/v1beta',
        ]]);

        Http::fake([
            'custom.api.example.com/*' => Http::response(fakeStoreResponse()),
        ]);

        Stores::get('store123', provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request->url(), 'custom.api.example.com/v1beta/fileSearchStores/store123'));
    }

    public function testRemovingADocumentDeletesTheFileItWasImportedFromNotTheDocument(): void
    {
        Http::fake([
            '*:importFile' => Http::response([
                'name' => 'fileSearchStores/store123/operations/import456',
                'done' => true,
                'response' => ['documentName' => 'fileSearchStores/store123/documents/file789-abc123'],
            ]),
            '*/fileSearchStores/store123' => Http::response(fakeStoreResponse()),
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $store = Stores::get('store123', provider: 'gemini');

        $document = $store->add('files/file789');

        $this->assertSame('file789-abc123', $document->id());
        $this->assertSame('files/file789', $document->fileId());

        $this->assertTrue($store->remove($document, deleteFile: true));

        $deletes = Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => $request->method() === 'DELETE')
            ->map(fn (Request $request): string => (string) $request->url())
            ->values();

        // Gemini mints a document ID of its own, so deleting files/{document} would 403...
        $this->assertCount(2, $deletes);
        $this->assertStringContainsString('fileSearchStores/store123/documents/file789-abc123', $deletes[0]);
        $this->assertStringEndsWith('/files/file789', $deletes[1]);
        $this->assertStringNotContainsString('file789-abc123', $deletes[1]);
    }

    public function testDeletingAStoreForcesRemovalSoANonEmptyStoreStillDeletes(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $provider = geminiProvider();

        $this->assertTrue($provider->storeGateway()->deleteStore($provider, 'store123'));

        // Gemini rejects deleting a non-empty store unless force is a query parameter...
        $this->assertStringEndsWith('/fileSearchStores/store123?force=true', sentRequest()->url());
        $this->assertSame('DELETE', sentRequest()->method());
    }

    public function testRemovingADocumentSendsForceAsAQueryParameter(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $provider = geminiProvider();

        $provider->storeGateway()->removeFile($provider, 'store123', 'doc456');

        $this->assertStringEndsWith('/fileSearchStores/store123/documents/doc456?force=true', sentRequest()->url());
        $this->assertSame('', sentRequest()->body());
    }

    public function testDeleteStoreEncodesTheIdAsASinglePathSegment(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        Stores::delete('fileSearchStores/../files/abc123#', provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://generativelanguage.googleapis.com/v1beta/fileSearchStores/..%2Ffiles%2Fabc123%23?force=true');
    }

    public function testRemoveFileEncodesTheDocumentIdAsASinglePathSegment(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $provider = geminiProvider();
        $provider->storeGateway()->removeFile($provider, 'store123', 'documents/../../store456');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_starts_with((string) $request->url(), 'https://generativelanguage.googleapis.com/v1beta/fileSearchStores/store123/documents/..%2F..%2Fstore456'));
    }

    public function testRemoveFileRebuildsAFullDocumentPathFromEncodedSegments(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        $provider = geminiProvider();
        $provider->storeGateway()->removeFile($provider, 'store123', 'fileSearchStores/store123/documents/doc456?force=false');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_starts_with((string) $request->url(), 'https://generativelanguage.googleapis.com/v1beta/fileSearchStores/store123/documents/doc456%3Fforce%3Dfalse'));
    }
}
