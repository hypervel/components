<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use DateInterval;
use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Providers\FileProvider;
use Hypervel\Ai\Contracts\Providers\StoreProvider;
use Hypervel\Ai\Files;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\LocalDocument;
use Hypervel\Ai\Files\ProviderDocument;
use Hypervel\Ai\Responses\AddedDocumentResponse;
use Hypervel\Ai\Responses\Data\StoreFileCounts;
use Hypervel\Ai\Responses\StoredFileResponse;
use Hypervel\Ai\Store;
use Hypervel\Ai\Stores;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Collection;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;

use function Hypervel\Support\days;

class StoreFakeTest extends TestCase
{
    public function testStoresCanBeFaked(): void
    {
        Stores::fake([
            'first-store',
            fn (string $storeId): string => "store-{$storeId}",
            'Custom Store',
        ]);

        $response = Stores::get('vs_1');
        $this->assertSame('vs_1', $response->id);
        $this->assertSame('first-store', $response->name);

        $response = Stores::get('vs_2');
        $this->assertSame('vs_2', $response->id);
        $this->assertSame('store-vs_2', $response->name);

        $response = Stores::get('vs_3');
        $this->assertSame('vs_3', $response->id);
        $this->assertSame('Custom Store', $response->name);
    }

    public function testStoresCanBeFakedWithNoPredefinedResponses(): void
    {
        Stores::fake();

        $response = Stores::get('vs_1');

        $this->assertSame('vs_1', $response->id);
        $this->assertSame('fake-store', $response->name);
    }

    public function testStoresCanBeFakedWithAClosure(): void
    {
        Stores::fake(fn (string $storeId): string => "name-for-{$storeId}");

        $response = Stores::get('vs_1');

        $this->assertSame('vs_1', $response->id);
        $this->assertSame('name-for-vs_1', $response->name);
    }

    public function testStoresCanPreventStrayOperations(): void
    {
        Stores::fake()->preventStrayOperations();

        $this->expectException(RuntimeException::class);
        Stores::get('vs_1');
    }

    public function testCanAssertStoreWasCreatedByName(): void
    {
        Stores::fake();

        Stores::create('My Vector Store');

        Stores::assertCreated('My Vector Store');
        Stores::assertNotCreated('Other Store');
    }

    public function testCanAssertStoreWasCreatedWithClosure(): void
    {
        Stores::fake();

        Stores::create(
            name: 'My Vector Store',
            description: 'A test store',
            expiresWhenIdleFor: days(7),
        );

        Stores::assertCreated(fn (string $name): bool => $name === 'My Vector Store');
        Stores::assertCreated(fn (string $name, ?string $description): bool => $description === 'A test store');

        Stores::assertCreated(fn (
            string $name,
            ?string $description,
            Collection $fileIds,
            ?DateInterval $expiresWhenIdleFor
        ): bool => $expiresWhenIdleFor instanceof DateInterval);

        Stores::assertNotCreated(fn (string $name): bool => $name === 'Other Store');
    }

    public function testCanAssertNoStoresWereCreated(): void
    {
        Stores::fake();

        Stores::assertNothingCreated();
    }

    public function testCanAssertStoreWasDeleted(): void
    {
        Stores::fake();

        Stores::delete('vs_123');

        Stores::assertDeleted('vs_123');
        Stores::assertDeleted(fn (string $id): bool => $id === 'vs_123');

        Stores::assertNotDeleted('vs_456');
        Stores::assertNotDeleted(fn (string $id): bool => $id === 'vs_456');
    }

    public function testCanAssertNoStoresWereDeleted(): void
    {
        Stores::fake();

        Stores::assertNothingDeleted();
    }

    public function testCanAddFileToStoreWithProviderId(): void
    {
        Stores::fake();

        $store = Stores::create('My Store');

        $searchable = $store->add(new ProviderDocument('file_123'));

        $this->assertSame('file_123', $searchable->id);
        $this->assertSame('file_123', $searchable->fileId());
    }

    public function testCanRemoveFileFromStoreWithProviderId(): void
    {
        Stores::fake();

        $result = Stores::create('My Store')->remove(new ProviderDocument('file_123'));

        $this->assertTrue($result);
    }

    public function testCanRemoveFileFromStoreWithStringId(): void
    {
        Stores::fake();

        $result = Stores::create('My Store')->remove('file_123');

        $this->assertTrue($result);
    }

    public function testCanAddStorableFileToStore(): void
    {
        Stores::fake();

        $response = Stores::create('My Store')
            ->add(Document::fromString('Hello, world!', 'text/plain'));

        $this->assertNotEmpty($response);

        Files::assertStored(
            fn (StorableFile $file): bool => $file->content() === 'Hello, world!'
        );
    }

    public function testCanAddAnUploadedFileToStoreFromItsPath(): void
    {
        Stores::fake();

        Stores::create('My Store')
            ->add(new UploadedFile(__DIR__ . '/../Fixtures/report.txt', 'report.txt', 'text/plain'));

        Files::assertStored(fn (StorableFile $file): bool => $file instanceof LocalDocument);
        Files::assertStored(fn (StorableFile $file): bool => $file->name() === 'report.txt');
        Files::assertStored(fn (StorableFile $file): bool => $file->mimeType() === 'text/plain');
        Files::assertStored(fn (StorableFile $file): bool => trim((string) $file) === 'I am an expense report.');
    }

    public function testAnAddedFakeUploadCanStillBeReadAfterTheUploadObjectIsGone(): void
    {
        Stores::fake();

        Stores::create('My Store')
            ->add(UploadedFile::fake()->createWithContent('report.txt', 'I am an expense report.'));

        Files::assertStored(fn (StorableFile $file): bool => trim((string) $file) === 'I am an expense report.');
    }

    public function testCannotAddAnUploadedFileThatFailedToUploadToStore(): void
    {
        Stores::fake();

        $this->expectException(InvalidArgumentException::class);
        Stores::create('My Store')
            ->add(new UploadedFile('', 'report.txt', 'text/plain', UPLOAD_ERR_NO_TMP_DIR));
    }

    public function testCanAssertFileAddedToStore(): void
    {
        Stores::fake();

        $store = Stores::create('My Store');
        $file = new ProviderDocument(Files::fakeId('test.txt'));

        $store->add($file);

        // Using closure receives the original file...
        $store->assertAdded(fn (mixed $candidate): bool => $candidate instanceof ProviderDocument && $candidate->id() === $file->id());

        // Using exact IDs...
        $store->assertAdded($file->id());

        // Using friendly names (automatically converted to fake IDs)...
        $store->assertAdded('test.txt');
    }

    public function testCanAssertFileAddedToStoreWithStorableFile(): void
    {
        Stores::fake();

        $store = Stores::create('My Store');

        $store->add(Document::fromString('Hello, world!', 'text/plain')->as('hello.txt'));

        // Using closure receives the original StorableFile...
        $store->assertAdded(fn (StorableFile $file): bool => $file->name() === 'hello.txt');
        $store->assertAdded(fn (StorableFile $file): bool => $file->content() === 'Hello, world!');
    }

    public function testCanAssertFileNotAddedToStore(): void
    {
        Stores::fake();

        $store = Stores::create('My Store');
        $file = new ProviderDocument('file_123');

        $store->add($file);

        $store->assertNotAdded(fn (mixed $candidate): bool => $candidate instanceof ProviderDocument && $candidate->id() === 'file_456');
    }

    public function testCanAssertFileRemovedFromStore(): void
    {
        Stores::fake();

        $store = Stores::create('My Store');
        $fileId = Files::fakeId('test.txt');

        $store->remove($fileId);

        // Using closure...
        $store->assertRemoved(fn (string $removedId): bool => $removedId === $fileId);

        // Using exact IDs...
        $store->assertRemoved($fileId);

        // Using friendly names (automatically converted to fake IDs)...
        $store->assertRemoved('test.txt');
    }

    public function testCanAssertFileNotRemovedFromStore(): void
    {
        Stores::fake();

        $store = Stores::create('My Store');

        $store->remove('file_123');

        $store->assertNotRemoved(fn (string $fileId): bool => $fileId === 'file_456');
    }

    public function testFileOperationsUseTheCapturedProvider(): void
    {
        $provider = m::mock(FileProvider::class, StoreProvider::class);
        $provider->shouldReceive('name')->andReturn('unregistered');
        $file = Document::fromString('Hello, world!', 'text/plain');
        $stored = new StoredFileResponse('file_123');

        $provider->shouldReceive('putFile')->once()->with($file)->andReturn($stored);
        $provider->shouldReceive('addFileToStore')->once()->with('store_123', $stored, [])->andReturn('document_456');
        $provider->shouldReceive('removeFileFromStore')->once()->with('store_123', m::type(AddedDocumentResponse::class))->andReturnTrue();
        $provider->shouldReceive('deleteFile')->once()->with('file_123');

        $store = new Store($provider, 'store_123', 'My Store', new StoreFileCounts(0, 0, 0), true);
        $document = $store->add($file);

        $this->assertSame('document_456', $document->id());
        $this->assertTrue($store->remove($document, deleteFile: true));
    }

    public function testRemovingAFileCanFakeItsDeletion(): void
    {
        Stores::fake();

        $this->assertTrue(Stores::create('My Store')->remove('file_123', deleteFile: true));

        Files::assertDeleted('file_123');
    }

    public function testThrowingSequenceEntryIsConsumed(): void
    {
        $exception = new RuntimeException('Store lookup failed.');
        Stores::fake([fn (): never => throw $exception, 'Second Store']);

        try {
            Stores::get('vs_1');
            $this->fail('The first response must throw.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame('Second Store', Stores::get('vs_2')->name);
    }
}
