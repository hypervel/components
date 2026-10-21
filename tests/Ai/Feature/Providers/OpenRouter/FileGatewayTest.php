<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenRouter;

use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files;
use Hypervel\Ai\Files\Document;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Tests\Ai\Fixtures\multipartField;
use function Hypervel\Tests\Ai\Fixtures\sentRequest;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class FileGatewayTest extends TestCase
{
    /**
     * Configure the OpenRouter credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openrouter.key', 'test-key');
    }

    public function testGetFileSendsCorrectRequestAndExposesTheMimeType(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'id' => 'or_file_abc123',
                'mime_type' => 'text/csv',
            ]),
        ]);

        $response = Files::get('or_file_abc123', provider: 'openrouter');

        $this->assertSame('or_file_abc123', $response->id);
        $this->assertNotNull($response->raw);
        $this->assertSame('text/csv', $response->mimeType());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://openrouter.ai/api/v1/files/or_file_abc123'
            && $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testGetFileExposesANullMimeTypeWhenTheProviderOmitsIt(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['id' => 'or_file_abc123']),
        ]);

        $this->assertNull(Files::get('or_file_abc123', provider: 'openrouter')->mimeType());
    }

    public function testPutFileSendsTheFileAsAMultipartUpload(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['id' => 'or_file_uploaded123']),
        ]);

        $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
            provider: 'openrouter',
        );

        $this->assertSame('or_file_uploaded123', $response->id);
        $this->assertNotNull($response->raw);

        $request = sentRequest();
        $file = collect($request->data())->firstWhere('name', 'file');

        $this->assertSame('POST', $request->method());
        $this->assertSame('https://openrouter.ai/api/v1/files', $request->url());
        $this->assertStringContainsString('multipart/form-data', $request->header('Content-Type')[0] ?? '');
        $this->assertSame('Hello, World!', $file['contents']);
        $this->assertSame('hello.txt', $file['filename']);
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testProviderOptionsAreResolvedWithTheOpenrouterKey(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['id' => 'or_file_uploaded123']),
        ]);

        Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
            ->withProviderOptions(fn (Lab $provider): array => match ($provider) {
                Lab::OpenRouter => ['workspace_id' => 'ws_123'],
                default => [],
            })
            ->put(provider: 'openrouter');

        $this->assertSame('ws_123', multipartField(sentRequest(), 'workspace_id'));
    }

    public function testDeleteFileSendsCorrectRequest(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response(['id' => 'or_file_abc123', 'deleted' => true]),
        ]);

        Files::delete('or_file_abc123', provider: 'openrouter');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://openrouter.ai/api/v1/files/or_file_abc123'
            && $request->hasHeader('Authorization', 'Bearer test-key'));
    }
}
