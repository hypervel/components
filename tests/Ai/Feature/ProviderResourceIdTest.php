<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\AiManager;
use Hypervel\Ai\Files;
use Hypervel\Ai\Stores;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;

class ProviderResourceIdTest extends TestCase
{
    /**
     * Configure the provider credentials and the Azure OpenAI endpoint.
     */
    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');

        foreach (['openai', 'anthropic', 'openrouter', 'gemini'] as $provider) {
            $config->set("ai.providers.{$provider}.key", 'test-key');
        }

        $config->set('ai.providers.azure.key', 'test-key');
        $config->set('ai.providers.azure.url', 'https://test-resource.openai.azure.com');
    }

    /**
     * Fake every provider request.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    #[DataProvider('fileIds')]
    public function testFileIdsAreSentAsASinglePathSegment(string $provider, string $url, string $id, string $encoded): void
    {
        Files::delete($id, provider: $provider);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === sprintf($url, $encoded));
    }

    /**
     * Provide each file gateway's delete URL with every unsafe ID.
     */
    public static function fileIds(): iterable
    {
        yield from self::withUnsafeIds([
            'openai' => ['openai', 'https://api.openai.com/v1/files/%s'],
            'azure' => ['azure', 'https://test-resource.openai.azure.com/openai/v1/files/%s'],
            'anthropic' => ['anthropic', 'https://api.anthropic.com/v1/files/%s'],
            'openrouter' => ['openrouter', 'https://openrouter.ai/api/v1/files/%s'],
            'gemini' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/files/%s'],
        ]);
    }

    #[DataProvider('storeIds')]
    public function testStoreIdsAreSentAsASinglePathSegment(string $provider, string $url, string $id, string $encoded): void
    {
        Stores::delete($id, provider: $provider);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === sprintf($url, $encoded));
    }

    /**
     * Provide each store gateway's delete URL with every unsafe ID.
     */
    public static function storeIds(): iterable
    {
        yield from self::withUnsafeIds([
            'openai' => ['openai', 'https://api.openai.com/v1/vector_stores/%s'],
            'azure' => ['azure', 'https://test-resource.openai.azure.com/openai/v1/vector_stores/%s'],
            'gemini' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/fileSearchStores/%s?force=true'],
        ]);
    }

    #[DataProvider('storeDocumentIds')]
    public function testStoreDocumentIdsAreSentAsASinglePathSegment(string $provider, string $url, string $id, string $encoded): void
    {
        $instance = $this->app->make(AiManager::class)->instance($provider);

        $instance->storeGateway()->removeFile($instance, 'store123', $id);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === sprintf($url, $encoded));
    }

    /**
     * Provide each store gateway's document removal URL with every unsafe ID.
     */
    public static function storeDocumentIds(): iterable
    {
        yield from self::withUnsafeIds([
            'openai' => ['openai', 'https://api.openai.com/v1/vector_stores/store123/files/%s'],
            'azure' => ['azure', 'https://test-resource.openai.azure.com/openai/v1/vector_stores/store123/files/%s'],
            'gemini' => ['gemini', 'https://generativelanguage.googleapis.com/v1beta/fileSearchStores/store123/documents/%s?force=true'],
        ]);
    }

    #[DataProvider('prefixedGeminiIds')]
    public function testGeminiResourceIdsCannotNameADifferentResourceThroughTheirPrefix(string $operation, string $id, string $url): void
    {
        $gemini = $this->app->make(AiManager::class)->instance('gemini');

        match ($operation) {
            'file' => Files::delete($id, provider: 'gemini'),
            'store' => Stores::delete($id, provider: 'gemini'),
            'document' => $gemini->storeGateway()->removeFile($gemini, 'store123', $id),
        };

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === "https://generativelanguage.googleapis.com/v1beta/{$url}");
    }

    /**
     * Provide Gemini IDs whose prefix names a different resource.
     */
    public static function prefixedGeminiIds(): array
    {
        return [
            'file pointing at a store' => ['file', 'files/../fileSearchStores/victim', 'files/..%2FfileSearchStores%2Fvictim'],
            'store pointing at a document' => ['store', 'fileSearchStores/victim/documents/doc456', 'fileSearchStores/victim%2Fdocuments%2Fdoc456?force=true'],
            'document pointing at a store' => ['document', 'fileSearchStores/victim', 'fileSearchStores/store123/documents/fileSearchStores%2Fvictim?force=true'],
            'document in a different store' => ['document', 'fileSearchStores/other/documents/doc456', 'fileSearchStores/store123/documents/fileSearchStores%2Fother%2Fdocuments%2Fdoc456?force=true'],
        ];
    }

    #[TestWith([''])]
    #[TestWith(['.'])]
    #[TestWith(['..'])]
    public function testDotSegmentIdsAreRejectedBeforeARequestIsSent(string $id): void
    {
        try {
            Files::delete($id, provider: 'openai');
            $this->fail('The ID must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame("[{$id}] is not a valid provider resource ID.", $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * Combine each gateway URL with every unsafe ID and its single-segment encoding.
     *
     * @param array<string, array{string, string}> $gateways
     */
    private static function withUnsafeIds(array $gateways): iterable
    {
        $ids = [
            'parent traversal' => ['../../vector_stores/victim', '..%2F..%2Fvector_stores%2Fvictim'],
            'nested path' => ['abc/def', 'abc%2Fdef'],
            'query injection' => ['abc?force=true', 'abc%3Fforce%3Dtrue'],
            'fragment' => ['abc#x', 'abc%23x'],
            'encoded slash' => ['abc%2F..', 'abc%252F..'],
        ];

        foreach ($gateways as $gateway => $route) {
            foreach ($ids as $name => $id) {
                yield "{$gateway} {$name}" => [...$route, ...$id];
            }
        }
    }
}
