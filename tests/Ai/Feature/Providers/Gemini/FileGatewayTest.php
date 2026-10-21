<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Ai\Files;
use Hypervel\Ai\Files\Document;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Client\Request;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

use function Hypervel\Tests\Ai\Fixtures\multipartField;
use function Hypervel\Tests\Ai\Fixtures\multipartNestedField;
use function Hypervel\Tests\Ai\Fixtures\sentRequest;

require_once __DIR__ . '/../../../Fixtures/helpers.php';

class FileGatewayTest extends TestCase
{
    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.gemini.key', 'test-gemini-key');
    }

    public function testGetFileSendsCorrectRequest(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'name' => 'files/abc123',
                'mimeType' => 'text/plain',
            ]),
        ]);

        $response = Files::get('abc123', provider: 'gemini');

        $this->assertSame('files/abc123', $response->id);
        $this->assertNotNull($response->raw);
        $this->assertSame('text/plain', $response->mime);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains((string) $request->url(), 'v1beta/files/abc123')
            && $request->hasHeader('x-goog-api-key', 'test-gemini-key'));
    }

    public function testGetFileNormalizesIdWithPrefix(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'name' => 'files/abc123',
                'mimeType' => 'application/pdf',
            ]),
        ]);

        Files::get('files/abc123', provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request->url(), 'v1beta/files/abc123')
            && ! str_contains((string) $request->url(), 'files/files/'));
    }

    public function testPutFileSendsMultipartUpload(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'file' => [
                    'name' => 'files/uploaded123',
                ],
            ]),
        ]);

        $response = Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')->put(
            provider: 'gemini',
        );

        $this->assertSame('files/uploaded123', $response->id);
        $this->assertNotNull($response->raw);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains((string) $request->url(), '/upload/v1beta/files')
            && $request->hasHeader('x-goog-api-key', 'test-gemini-key'));
    }

    public function testPutFileMergesFlatProviderOptionsIntoTheUploadBody(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['file' => ['name' => 'files/uploaded123']]),
        ]);

        Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
            ->withProviderOptions(['mime_type' => 'image/png'])
            ->put(provider: 'gemini');

        $request = sentRequest();

        $this->assertSame('image/png', multipartField($request, 'mime_type'));
        $this->assertSame(['hello.txt'], multipartNestedField($request, 'file'));
    }

    public function testPutFileProviderOptionsOverrideOnlyTheFileMetadataKeysTheySpecify(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['file' => ['name' => 'files/uploaded123']]),
        ]);

        Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
            ->withProviderOptions(['file' => ['display_name' => 'override.txt']])
            ->put(provider: 'gemini');

        $this->assertSame(['override.txt'], multipartNestedField(sentRequest(), 'file'));
    }

    public function testPutFileProviderOptionsDeepMergeIntoTheFileMetadataWithoutWipingTheDisplayName(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['file' => ['name' => 'files/uploaded123']]),
        ]);

        Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')
            ->withProviderOptions(['file' => ['mime_type' => 'image/png']])
            ->put(provider: 'gemini');

        $this->assertSame(['hello.txt', 'image/png'], multipartNestedField(sentRequest(), 'file'));
    }

    public function testDeleteFileSendsCorrectRequest(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        Files::delete('abc123', provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains((string) $request->url(), 'v1beta/files/abc123')
            && $request->hasHeader('x-goog-api-key', 'test-gemini-key'));
    }

    public function testDeleteFileNormalizesIdWithPrefix(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        Files::delete('files/abc123', provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request->url(), 'v1beta/files/abc123')
            && ! str_contains((string) $request->url(), 'files/files/'));
    }

    public function testFileGatewayUsesCustomBaseUrl(): void
    {
        config(['ai.providers.gemini' => [
            ...config('ai.providers.gemini'),
            'key' => 'test-gemini-key',
            'url' => 'https://custom.api.example.com/v1beta',
        ]]);

        Http::fake([
            'custom.api.example.com/*' => Http::response([
                'name' => 'files/abc123',
                'mimeType' => 'text/plain',
            ]),
        ]);

        Files::get('abc123', provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => str_contains((string) $request->url(), 'custom.api.example.com/v1beta/files/abc123'));
    }

    public function testDeleteFileEncodesTheIdAsASinglePathSegment(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 200),
        ]);

        Files::delete('files/../fileSearchStores/store123?force=true', provider: 'gemini');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://generativelanguage.googleapis.com/v1beta/files/..%2FfileSearchStores%2Fstore123%3Fforce%3Dtrue');
    }

    public function testDeleteFileRejectsAPrefixedDotSegmentId(): void
    {
        Http::fake();

        try {
            Files::delete('files/..', provider: 'gemini');
            $this->fail('The ID must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('[..] is not a valid provider resource ID.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}
