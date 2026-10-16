<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\AzureOpenAi;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Providers\Tools\FileSearch;
use Hypervel\Ai\Stores;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

class StoreGatewayTest extends TestCase
{
    /**
     * Configure the Azure OpenAI endpoint.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');
        $config->set('ai.providers.azure', [
            ...$config->get('ai.providers.azure'),
            'key' => 'test-key',
            'url' => 'https://test-resource.openai.azure.com',
        ]);
    }

    /**
     * Build a vector store response.
     */
    private function fakeAzureStoreResponse(string $id = 'vs-123', string $name = 'Test Store'): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'status' => 'completed',
            'file_counts' => [
                'completed' => 5,
                'in_progress' => 1,
                'failed' => 0,
            ],
        ];
    }

    public function testGetStoreSendsRequestToTheV1EndpointWithTheApiKeyHeader(): void
    {
        Http::fake([
            'test-resource.openai.azure.com/*' => Http::response($this->fakeAzureStoreResponse()),
        ]);

        $this->assertSame('vs-123', Stores::get('vs-123', provider: 'azure')->id);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/vector_stores/vs-123'
            && $request->hasHeader('api-key', 'test-key'));
    }

    public function testCreateStoreSendsRequestToTheV1EndpointWithTheApiKeyHeader(): void
    {
        Http::fake([
            'test-resource.openai.azure.com/*' => Http::response($this->fakeAzureStoreResponse()),
        ]);

        $this->assertSame('vs-123', Stores::create('Test Store', provider: 'azure')->id);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://test-resource.openai.azure.com/openai/v1/vector_stores'
            && $request->hasHeader('api-key', 'test-key'));
    }

    public function testFileSearchMetadataFiltersThrowAnException(): void
    {
        $search = new FileSearch(['vs-123'], where: ['company' => 'hypervel']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Azure OpenAI does not support file search metadata filters.');
        Ai::storeProvider('azure')->fileSearchToolOptions($search);
    }

    public function testFileSearchWithoutFiltersReturnsVectorStoreIds(): void
    {
        $search = new FileSearch(['vs-123']);

        $this->assertSame(['vector_store_ids' => ['vs-123']], Ai::storeProvider('azure')->fileSearchToolOptions($search));
    }
}
