<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Events\FileDeleted;
use Hypervel\Ai\Events\FileStored;
use Hypervel\Ai\Events\StoringFile;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\UntrustedUrl;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use RuntimeException;

use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class FileTest extends TestCase
{
    #[DataProviderExternal(Providers::class, 'files')]
    public function testCanStoreFiles(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        Event::fake();

        $response = Document::fromString('Hello, World!', 'text/plain')->put(
            name: 'hello.txt',
            provider: $provider
        );

        try {
            $this->assertNotEmpty($response->id);

            Event::assertDispatched(StoringFile::class);
            Event::assertDispatched(FileStored::class);
        } finally {
            Document::fromId($response->id)->delete(provider: $provider);
        }

        Event::assertDispatched(FileDeleted::class);
    }

    #[DataProviderExternal(Providers::class, 'files')]
    public function testCanStoreFilesFromLocalPaths(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        $response = Document::fromPath(__DIR__ . '/../../Ai/Fixtures/document.txt')->put(
            name: 'document.txt',
            provider: $provider,
        );

        try {
            $this->assertNotEmpty($response->id);
        } finally {
            Document::fromId($response->id)->delete(provider: $provider);
        }
    }

    #[DataProviderExternal(Providers::class, 'files')]
    public function testCanStoreFilesFromStoragePaths(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        Storage::fake('local');
        Storage::disk('local')->put('document.txt', 'Hello, World!');

        $response = Document::fromStorage('document.txt', disk: 'local')->put(
            provider: $provider,
        );

        try {
            $this->assertNotEmpty($response->id);
        } finally {
            Document::fromId($response->id)->delete(provider: $provider);
        }
    }

    #[DataProviderExternal(Providers::class, 'files')]
    public function testCanStoreFilesFromRemotePaths(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        // This live download connects to the resolved address, so it needs real DNS.
        UntrustedUrl::resolveUsing(null);

        $stored = Document::fromUrl(
            'https://raw.githubusercontent.com/laravel/laravel/refs/heads/12.x/README.md'
        )->put(
            provider: $provider,
        );

        try {
            $this->assertNotEmpty($stored->id);

            $response = Document::fromId($stored->id)->get(provider: $provider);

            $this->assertContains($response->mime, ['text/plain', 'text/markdown', null]);
        } finally {
            Document::fromId($stored->id)->delete(provider: $provider);
        }
    }

    #[DataProviderExternal(Providers::class, 'files')]
    public function testExceptionIsThrownIfStoredFileDoesNotExist(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        Storage::fake('local');

        $this->expectException(RuntimeException::class);

        Document::fromStorage('missing-document.pdf', disk: 'local')->put(
            provider: $provider,
        );
    }

    #[DataProviderExternal(Providers::class, 'files')]
    public function testCanGetFiles(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        $stored = Document::fromString('Hello, World!', 'text/plain')->put(
            name: 'hello.txt',
            provider: $provider
        );

        try {
            $response = Document::fromId($stored->id)->get(provider: $provider);

            // Not every provider returns the MIME type when fetching a file.
            $this->assertSame($stored->id, $response->id);
            $this->assertContains($response->mime, ['text/plain', null]);
        } finally {
            Document::fromId($stored->id)->delete(provider: $provider);
        }
    }

    #[DataProviderExternal(Providers::class, 'files')]
    public function testCanDeleteFiles(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        $stored = Document::fromString('Hello, World!', 'text/plain')->put(
            name: 'hello.txt',
            provider: $provider
        );

        Document::fromId($stored->id)->delete(provider: $provider);

        $this->expectException(RequestException::class);

        Document::fromId($stored->id)->get(provider: $provider);
    }
}
