<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\Events\AddingFileToStore;
use Hypervel\Ai\Events\AddingFileToStoreFailed;
use Hypervel\Ai\Events\CreatingStore;
use Hypervel\Ai\Events\CreatingStoreFailed;
use Hypervel\Ai\Events\RemovingFileFromStore;
use Hypervel\Ai\Events\RemovingFileFromStoreFailed;
use Hypervel\Ai\Events\StoringFile;
use Hypervel\Ai\Events\StoringFileFailed;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\ProviderDocument;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

class FileAndStoreEventsTest extends TestCase
{
    /**
     * Configure the provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.openai.key', 'test-key');
    }

    #[DataProvider('operations')]
    public function testFileAndStoreFailuresDispatchAnEventMirroringTheStartingEventAndRethrow(Closure $invoke, string $startingEvent, string $failureEvent): void
    {
        Event::fake();

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'Server error.']], 500),
        ]);

        $caught = null;

        try {
            $invoke($this->app->make(AiManager::class)->instance('openai'));
        } catch (Throwable $e) {
            $caught = $e;
        }

        Event::assertDispatchedTimes($startingEvent, 1);
        Event::assertDispatchedTimes($failureEvent, 1);

        $started = Event::dispatched($startingEvent)->first()[0];
        $failed = Event::dispatched($failureEvent)->first()[0];

        $this->assertNotNull($caught);
        $this->assertSame($caught, $failed->exception);
        $this->assertSame(get_object_vars($started), Arr::except(get_object_vars($failed), 'exception'));
    }

    /**
     * Provide each file and store operation with its starting and failure events.
     */
    public static function operations(): array
    {
        return [
            'storing a file' => [
                fn ($provider) => $provider->putFile(Document::fromString('Hello, World!', 'text/plain')->as('hello.txt')),
                StoringFile::class,
                StoringFileFailed::class,
            ],
            'creating a store' => [
                fn ($provider) => $provider->createStore('Knowledge Base', 'Product docs'),
                CreatingStore::class,
                CreatingStoreFailed::class,
            ],
            'adding a file to a store' => [
                fn ($provider) => $provider->addFileToStore('vs-123', new ProviderDocument('file-abc123')),
                AddingFileToStore::class,
                AddingFileToStoreFailed::class,
            ],
            'removing a file from a store' => [
                fn ($provider) => $provider->removeFileFromStore('vs-123', 'file-abc123'),
                RemovingFileFromStore::class,
                RemovingFileFromStoreFailed::class,
            ],
        ];
    }
}
