<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

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
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.anthropic.key', 'test-key');
    }

    public function testGetFileSendsCorrectRequest(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['id' => 'file-abc123', 'mime_type' => 'text/plain']),
        ]);

        $response = Files::get('file-abc123', provider: 'anthropic');

        $this->assertSame('file-abc123', $response->id);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.anthropic.com/v1/files/file-abc123'
            && $request->hasHeader('x-api-key', 'test-key'));
    }

    public function testPutFileSendsMultipartUpload(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['id' => 'file-uploaded123']),
        ]);

        $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
            provider: 'anthropic',
        );

        $this->assertSame('file-uploaded123', $response->id);

        $request = sentRequest();

        $this->assertSame('POST', $request->method());
        $this->assertSame('https://api.anthropic.com/v1/files', $request->url());
        $this->assertStringContainsString('multipart/form-data', $request->header('Content-Type')[0] ?? '');
        $this->assertTrue($request->hasHeader('x-api-key', 'test-key'));
    }

    public function testPutFileForwardsProviderOptionsIntoTheMultipartUpload(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['id' => 'file-uploaded123']),
        ]);

        Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
            ->withProviderOptions(['custom_field' => 'value'])
            ->put(provider: 'anthropic');

        $this->assertSame('value', multipartField(sentRequest(), 'custom_field'));
    }

    public function testDeleteFileSendsCorrectRequest(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['id' => 'file-abc123']),
        ]);

        Files::delete('file-abc123', provider: 'anthropic');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.anthropic.com/v1/files/file-abc123'
            && $request->hasHeader('x-api-key', 'test-key'));
    }
}
