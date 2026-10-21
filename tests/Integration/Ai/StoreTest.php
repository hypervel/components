<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Ai;

use Hypervel\Ai\Events\CreatingStore;
use Hypervel\Ai\Events\StoreCreated;
use Hypervel\Ai\Events\StoreDeleted;
use Hypervel\Ai\Files;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Providers\Tools\FileSearch;
use Hypervel\Ai\Providers\Tools\FileSearchQuery;
use Hypervel\Ai\Store;
use Hypervel\Ai\Stores;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Ai\Fixtures\Tools\FixedNumberGenerator;
use Hypervel\Tests\Ai\TestCase;
use Hypervel\Tests\Integration\Ai\Fixtures\Providers;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use RuntimeException;

use function Hypervel\Ai\agent;
use function Hypervel\Support\days;
use function Hypervel\Tests\Ai\Fixtures\requiresApiKey;

require_once __DIR__ . '/../../Ai/Fixtures/helpers.php';

class StoreTest extends TestCase
{
    #[DataProviderExternal(Providers::class, 'stores')]
    public function testCanCreateGetAndDeleteStore(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        Event::fake();

        $created = Stores::create('Test Store', provider: $provider);

        try {
            $this->assertNotEmpty($created->id);

            Event::assertDispatched(CreatingStore::class);
            Event::assertDispatched(StoreCreated::class);

            $retrieved = Stores::get($created->id, provider: $provider);

            $this->assertSame($created->id, $retrieved->id);
            $this->assertSame('Test Store', $retrieved->name);
            $this->assertSame(0, $retrieved->fileCounts->completed);
            $this->assertIsBool($retrieved->ready);
        } finally {
            $deleted = Stores::delete($created->id, provider: $provider);
        }

        $this->assertTrue($deleted);

        Event::assertDispatched(StoreDeleted::class);
    }

    #[DataProviderExternal(Providers::class, 'stores')]
    public function testCanCreateStoreWithExpiration(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        $created = Stores::create(
            name: 'Expiring Store',
            description: 'A store that expires after 7 days of inactivity.',
            expiresWhenIdleFor: days(7),
            provider: $provider,
        );

        try {
            $this->assertNotEmpty($created->id);
        } finally {
            Stores::delete($created->id, provider: $provider);
        }
    }

    #[DataProviderExternal(Providers::class, 'stores')]
    public function testCanAddAndRemoveFileFromStore(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        // Create a store...
        $store = Stores::create('File Test Store', provider: $provider);
        $this->beforeApplicationDestroyed(fn (): bool => $store->delete());

        // Upload a file to the provider...
        $file = Files::put(
            Document::fromString('This is test content for the vector store.', 'text/plain')->as('test.txt'),
            provider: $provider,
        );

        $this->beforeApplicationDestroyed(function () use (&$file, $provider): void {
            if ($file !== null) {
                Files::delete($file->id, provider: $provider);
            }
        });

        // Add the file to the store...
        $documentId = $store->add($file);

        $this->assertNotEmpty($documentId->id);

        // Refresh the store to see updated file counts...
        $refreshed = $store->refresh();

        $this->assertGreaterThanOrEqual(0, $refreshed->fileCounts->completed + $refreshed->fileCounts->pending);

        // Remove the file from the store....
        $removed = $store->remove($documentId, deleteFile: true);

        if ($removed) {
            $file = null;
        }

        $this->assertTrue($removed);
    }

    #[DataProviderExternal(Providers::class, 'fileSearch')]
    public function testCanActuallyPromptAnAgentWithFileSearchData(string $provider, string $apiKey): void
    {
        requiresApiKey($apiKey);

        $store = $this->createFileSearchStore($provider);

        $response = agent(
            instructions: 'You will use the file search tool available to you to answer questions about the documents you have access to.',
            tools: [
                new FileSearch([$store->id]),
            ],
        )->prompt('Is Valkey mentioned in the sixth month roadmap? Can you quote the section where it is mentioned?', provider: $provider);

        $this->assertStringContainsString('Yes', (string) $response);
        $this->assertStringContainsString('Valkey', (string) $response);
    }

    public function testCanFollowUpAStatelessFileSearchAndFunctionCallMadeInTheSameStep(): void
    {
        requiresApiKey('OPENAI_API_KEY');

        config(['ai.providers.openai.store' => false]);

        $store = $this->createFileSearchStore('openai');

        $response = agent(
            instructions: 'In your first step, use the file search tool and the number generator tool together. '
                . 'Then answer using the results of both.',
            tools: [
                new FileSearch([$store->id]),
                new FixedNumberGenerator,
            ],
        )->prompt('Is Valkey mentioned in the sixth month roadmap, and what number does the generator return?', provider: 'openai');

        $firstStep = $response->steps->first();

        // Both items must come from one step, or no follow-up replays the file_search_call and the bug never runs...
        $this->assertContains('file_search_call', collect($firstStep->providerToolCalls)->pluck('type'));
        $this->assertNotEmpty($firstStep->toolCalls);
        $this->assertStringContainsString('72019', (string) $response);
        $this->assertStringContainsString('Valkey', (string) $response);
    }

    public function testCanActuallyPromptAnAgentWithFilteredSearchData(): void
    {
        requiresApiKey('OPENAI_API_KEY');

        $store = $this->createFileSearchStore('openai');

        $instructions = 'Answer strictly based on the documents returned by the file search tool. '
            . 'Do not use prior knowledge. Respond with exactly one word: "Yes" or "No".';
        $prompt = 'Do any of the documents you have access to mention Valkey?';

        $response = agent(
            instructions: $instructions,
            tools: [
                new FileSearch([$store->id], where: ['company' => 'tailwind']),
            ],
        )->prompt($prompt, provider: 'openai');

        $this->assertStringStartsWith('No', trim((string) $response));

        $response = agent(
            instructions: $instructions,
            tools: [
                new FileSearch(
                    [$store->id],
                    where: fn (FileSearchQuery $query): FileSearchQuery => $query->where('company', 'hypervel')
                ),
            ],
        )->prompt($prompt, provider: 'openai');

        $this->assertStringStartsWith('Yes', trim((string) $response));
    }

    /**
     * Create an indexed store and clean up its resources even if setup fails.
     */
    protected function createFileSearchStore(string $provider): Store
    {
        $store = Stores::create('Hypervel AI SDK Integration Test Store', provider: $provider);
        $this->beforeApplicationDestroyed(fn (): bool => $store->delete());

        foreach (['hypervel', 'tailwind'] as $company) {
            // Upload separately so cleanup retains the ID if adding or indexing fails.
            $file = Files::put(Document::fromPath(__DIR__ . '/Fixtures/' . $company . '-roadmap.txt'), provider: $provider);
            $this->beforeApplicationDestroyed(function () use ($file, $provider): void {
                Files::delete($file->id, provider: $provider);
            });

            $store->add($file, metadata: ['company' => $company]);
        }

        return retry(60, function () use ($store): Store {
            $refreshed = $store->refresh();

            if ($refreshed->fileCounts->completed < 2) {
                throw new RuntimeException("Store {$refreshed->id} has only {$refreshed->fileCounts->completed} of 2 files indexed.");
            }

            return $refreshed;
        }, 2000);
    }
}
