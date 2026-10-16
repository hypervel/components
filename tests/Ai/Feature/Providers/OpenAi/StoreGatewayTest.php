<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use Hypervel\Ai\AiManager;
use Hypervel\Ai\Providers\OpenAiProvider;
use Hypervel\Ai\Stores;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

use function Hypervel\Support\days;

class StoreGatewayTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testGetStoreSendsCorrectRequest(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->fakeOpenAiStoreResponse())]);

        $store = Stores::get('vs-123', provider: 'openai');

        $this->assertSame('vs-123', $store->id);
        $this->assertSame('Test Store', $store->name);
        $this->assertSame(5, $store->fileCounts->completed);
        $this->assertSame(1, $store->fileCounts->pending);
        $this->assertSame(0, $store->fileCounts->failed);
        $this->assertTrue($store->ready);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123'
            && $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testCreateStoreSendsCorrectRequest(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->fakeOpenAiStoreResponse())]);

        $store = Stores::create('Test Store', provider: 'openai');

        $this->assertSame('vs-123', $store->id);
        $this->assertSame('Test Store', $store->name);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.openai.com/v1/vector_stores'
            && ($request->data()['name'] ?? null) === 'Test Store');
    }

    public function testCreateStoreMapsDescriptionToMetadata(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->fakeOpenAiStoreResponse())]);

        Stores::create('Test Store', description: 'A test store', provider: 'openai');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && ! array_key_exists('description', $request->data())
            && ($request->data()['metadata'] ?? null) === ['description' => 'A test store']);
    }

    public function testCreateStoreIncludesFileIdsInRequest(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->fakeOpenAiStoreResponse())]);

        Stores::create('Test Store', fileIds: ['file-1', 'file-2'], provider: 'openai');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && ($request->data()['file_ids'] ?? null) === ['file-1', 'file-2']);
    }

    public function testCreateStoreIncludesExpirationWhenProvided(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->fakeOpenAiStoreResponse())]);

        Stores::create('Expiring Store', expiresWhenIdleFor: days(7), provider: 'openai');

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST') {
                return false;
            }

            $expires = $request->data()['expires_after'] ?? [];

            return ($expires['anchor'] ?? null) === 'last_active_at'
                && ($expires['days'] ?? null) === 7;
        });
    }

    public function testAddFileSendsCorrectRequest(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'doc-456'])]);
        $provider = $this->openAiProvider();

        $documentId = $provider->storeGateway()->addFile($provider, 'vs-123', 'file-789');

        $this->assertSame('doc-456', $documentId);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123/files'
            && ($request->data()['file_id'] ?? null) === 'file-789');
    }

    #[DataProvider('metadata')]
    public function testAddFileWithMetadataIncludesAttributes(array $metadata): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'doc-456'])]);
        $provider = $this->openAiProvider();

        $provider->storeGateway()->addFile($provider, 'vs-123', 'file-789', $metadata);

        Http::assertSent(fn (Request $request): bool => (json_decode($request->body(), true)['attributes'] ?? null) === $metadata
            && json_decode($request->body())->attributes instanceof stdClass);
    }

    /**
     * Supply metadata keys that must remain JSON object properties.
     */
    public static function metadata(): array
    {
        return ['named' => [['company' => 'hypervel']], 'numeric' => [['0' => 'hypervel']]];
    }

    public function testRemoveFileSendsCorrectRequest(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['deleted' => true])]);
        $provider = $this->openAiProvider();

        $result = $provider->storeGateway()->removeFile($provider, 'vs-123', 'doc-456');

        $this->assertTrue($result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123/files/doc-456');
    }

    public function testDeleteStoreSendsCorrectRequest(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['deleted' => true])]);

        $result = Stores::delete('vs-123', provider: 'openai');

        $this->assertTrue($result);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.openai.com/v1/vector_stores/vs-123'
            && $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    /**
     * Resolve the OpenAI provider.
     */
    private function openAiProvider(): OpenAiProvider
    {
        return $this->app->make(AiManager::class)->instance('openai');
    }

    /**
     * Create a store response.
     */
    private function fakeOpenAiStoreResponse(string $id = 'vs-123', string $name = 'Test Store'): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'status' => 'completed',
            'file_counts' => ['completed' => 5, 'in_progress' => 1, 'failed' => 0],
        ];
    }
}
