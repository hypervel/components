<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

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
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    public function testGetFileSendsCorrectRequest(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'file-abc123'])]);

        $response = Files::get('file-abc123', provider: 'openai');

        $this->assertSame('file-abc123', $response->id);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.openai.com/v1/files/file-abc123'
            && $request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testPutFileSendsMultipartUploadWithUserDataPurpose(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'file-uploaded123'])]);

        $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(provider: 'openai');
        $this->assertSame('file-uploaded123', $response->id);
        $request = sentRequest();

        $this->assertSame('POST', $request->method());
        $this->assertSame('https://api.openai.com/v1/files', $request->url());
        $this->assertStringContainsString('multipart/form-data', $request->header('Content-Type')[0] ?? '');
        $this->assertSame('user_data', multipartField($request, 'purpose'));
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-key'));
    }

    public function testPutFileAllowsOverridingThePurposeViaProviderOptions(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'file-uploaded123'])]);

        Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
            ->withProviderOptions(['purpose' => 'fine-tune'])->put(provider: 'openai');
        $request = sentRequest();

        $this->assertSame('POST', $request->method());
        $this->assertSame('https://api.openai.com/v1/files', $request->url());
        $this->assertSame('fine-tune', multipartField($request, 'purpose'));
    }

    public function testPutFileResolvesProviderOptionsFromAClosureScopedToTheProvider(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'file-uploaded123'])]);

        Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
            ->withProviderOptions(fn (Lab $provider): array => match ($provider) {
                Lab::OpenAI => ['purpose' => 'assistants'],
                default => [],
            })->put(provider: 'openai');

        $this->assertSame('assistants', multipartField(sentRequest(), 'purpose'));
    }

    public function testDeleteFileSendsCorrectRequest(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['id' => 'file-abc123', 'deleted' => true])]);

        Files::delete('file-abc123', provider: 'openai');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.openai.com/v1/files/file-abc123'
            && $request->hasHeader('Authorization', 'Bearer test-key'));
    }
}
