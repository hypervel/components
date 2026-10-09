<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Files;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\LocalDocument;
use Hypervel\Ai\Responses\FileResponse;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Facades\Storage;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;
use RuntimeException;

class FileFakeTest extends TestCase
{
    public function testFilesCanBeFaked(): void
    {
        Files::fake([
            'first-content',
            fn (string $fileId): string => "content-for-{$fileId}",
            new FileResponse('id', mimeType: 'application/json', content: 'third-content'),
        ]);

        $response = Files::get('file_1');
        $this->assertSame('file_1', $response->id);
        $this->assertSame('first-content', $response->content);

        $response = Files::get('file_2');
        $this->assertSame('file_2', $response->id);
        $this->assertSame('content-for-file_2', $response->content);

        $response = Files::get('file_3');
        $this->assertSame('id', $response->id);
        $this->assertSame('third-content', $response->content);
    }

    public function testFilesCanBeFakedWithNoPredefinedResponses(): void
    {
        Files::fake();

        $response = Files::get('file_1');
        $this->assertSame('file_1', $response->id);
        $this->assertSame('fake-content', $response->content);

        $response = Files::get('file_2');
        $this->assertSame('file_2', $response->id);
        $this->assertSame('fake-content', $response->content);
    }

    public function testFilesCanBeFakedWithAClosure(): void
    {
        Files::fake(fn (string $fileId): string => "content-for-{$fileId}");

        $response = Files::get('file_1');
        $this->assertSame('file_1', $response->id);
        $this->assertSame('content-for-file_1', $response->content);

        $response = Files::get('file_2');
        $this->assertSame('file_2', $response->id);
        $this->assertSame('content-for-file_2', $response->content);
    }

    public function testFilesCanPreventStrayOperations(): void
    {
        Files::fake()->preventStrayOperations();

        $this->expectException(RuntimeException::class);
        Files::get('file_1');
    }

    public function testCanAssertFileWasStored(): void
    {
        Files::fake();

        $id = Document::fromString('Hello, World!', 'text/plain')->as('document.txt')->put()->id;
        $this->assertSame(Files::fakeId('document.txt'), $id);

        Document::fromPath(__DIR__ . '/../Fixtures/document.txt')->put();
        Document::fromUpload(new UploadedFile(__DIR__ . '/../Fixtures/report.txt', 'report.txt'))->put();

        Files::assertStored(fn (StorableFile $file): bool => (string) $file === 'Hello, World!');

        Files::assertStored(fn (StorableFile $file): bool => trim((string) $file) === 'I am a local document.');
        Files::assertStored(fn (StorableFile $file): bool => $file->name() === 'document.txt');

        Files::assertStored(fn (StorableFile $file): bool => trim((string) $file) === 'I am an expense report.');
        Files::assertStored(fn (StorableFile $file): bool => $file->name() === 'report.txt');

        Files::assertStored(fn (StorableFile $file): bool => $file->mimeType() === 'text/plain');
        Files::assertNotStored(fn (StorableFile $file): bool => $file->mimeType() === 'application/json');
    }

    public function testCanAssertNoFilesWereStored(): void
    {
        Files::fake();

        Files::assertNothingStored();
    }

    public function testCanStoreAnUploadedFileFromItsPath(): void
    {
        Files::fake();

        Files::put(new UploadedFile(__DIR__ . '/../Fixtures/report.txt', 'report.txt', 'text/plain'));

        Files::assertStored(fn (StorableFile $file): bool => $file instanceof LocalDocument);
        Files::assertStored(fn (StorableFile $file): bool => $file->name() === 'report.txt');
        Files::assertStored(fn (StorableFile $file): bool => $file->mimeType() === 'text/plain');
        Files::assertStored(fn (StorableFile $file): bool => trim((string) $file) === 'I am an expense report.');
    }

    public function testAStoredFakeUploadCanStillBeReadAfterTheUploadObjectIsGone(): void
    {
        Files::fake();

        Files::put(UploadedFile::fake()->createWithContent('report.txt', 'I am an expense report.'));

        Files::assertStored(fn (StorableFile $file): bool => trim((string) $file) === 'I am an expense report.');
    }

    public function testStoringAnUploadedFileDoesNotCopyItToAnotherTemporaryPath(): void
    {
        Files::fake();

        $upload = UploadedFile::fake()->createWithContent('report.txt', 'I am an expense report.');

        Files::put($upload);

        Files::assertStored(fn (StorableFile $file): bool => $file->path === $upload->getPathname());
    }

    public function testCannotStoreAnUploadedFileThatFailedToUpload(): void
    {
        Files::fake();

        $this->expectException(InvalidArgumentException::class);
        Files::put(new UploadedFile('', 'report.txt', 'text/plain', UPLOAD_ERR_NO_TMP_DIR));
    }

    public function testCanOverrideTheFilenameWhenStoringFilesFromEachDocumentConstructor(): void
    {
        Files::fake();

        Document::fromString('Hello, World!', 'text/plain')->put(name: 'custom-name.txt');
        Document::fromPath(__DIR__ . '/../Fixtures/document.txt')->put(name: 'renamed-document.txt');
        Document::fromUpload(new UploadedFile(__DIR__ . '/../Fixtures/report.txt', 'report.txt'))
            ->put(name: 'renamed-report.txt');

        Files::assertStored(fn (StorableFile $file): bool => $file->name() === 'custom-name.txt');
        Files::assertStored(fn (StorableFile $file): bool => $file->name() === 'renamed-document.txt');
        Files::assertStored(fn (StorableFile $file): bool => $file->name() === 'renamed-report.txt');
    }

    public function testCanOverrideTheFilenameWhenStoringAnExistingStorableFile(): void
    {
        Files::fake();

        Files::put(Document::fromPath(__DIR__ . '/../Fixtures/document.txt'), name: 'override.txt');

        Files::assertStored(fn (StorableFile $file): bool => $file->name() === 'override.txt');
    }

    public function testCanOverrideTheFilenameWhenStoringFilesFromALocalPath(): void
    {
        Files::fake();

        Files::putFromPath(__DIR__ . '/../Fixtures/document.txt', name: 'from-path.txt');

        Files::assertStored(fn (StorableFile $file): bool => $file->name() === 'from-path.txt');
    }

    public function testCanOverrideTheFilenameWhenStoringFilesFromAStorageDisk(): void
    {
        Files::fake();

        Storage::fake('docs');
        Storage::disk('docs')->put('original.txt', 'contents');

        Files::putFromStorage('original.txt', disk: 'docs', name: 'from-storage.txt');

        Files::assertStored(fn (StorableFile $file): bool => $file->name() === 'from-storage.txt');
    }

    public function testCanOverrideTheMimeTypeWhenStoringFilesFromEachSource(): void
    {
        Files::fake();

        Storage::fake('docs');
        Storage::disk('docs')->put('original.txt', 'contents');

        Files::put(Document::fromPath(__DIR__ . '/../Fixtures/document.txt'), mimeType: 'application/x-local');
        Files::putFromPath(__DIR__ . '/../Fixtures/document.txt', mimeType: 'application/x-path');
        Files::put(Document::fromStorage('original.txt', 'docs'), mimeType: 'application/x-storage');

        Files::assertStored(fn (StorableFile $file): bool => $file->mimeType() === 'application/x-local');
        Files::assertStored(fn (StorableFile $file): bool => $file->mimeType() === 'application/x-path');
        Files::assertStored(fn (StorableFile $file): bool => $file->mimeType() === 'application/x-storage');
        Files::assertNotStored(fn (StorableFile $file): bool => $file->mimeType() === 'text/plain');
    }

    public function testCanOverrideTheNameAndMimeTypeWhenStoringAnExistingStorableFile(): void
    {
        Files::fake();

        Files::put(Document::fromPath(__DIR__ . '/../Fixtures/document.txt'), mimeType: 'application/json', name: 'renamed.txt');

        Files::assertStored(fn (StorableFile $file): bool => $file->name() === 'renamed.txt');
        Files::assertStored(fn (StorableFile $file): bool => $file->mimeType() === 'application/json');
        Files::assertNotStored(fn (StorableFile $file): bool => $file->mimeType() === 'text/plain');
    }

    public function testCanAssertFileWasDeleted(): void
    {
        Files::fake();

        Files::delete('file_123');

        Files::assertDeleted('file_123');
        Files::assertDeleted(fn (string $id): bool => $id === 'file_123');
        Files::assertNotDeleted('file_456');
        Files::assertNotDeleted(fn (string $id): bool => $id === 'file_456');
    }

    public function testCanAssertNoFilesWereDeleted(): void
    {
        Files::fake();

        Files::assertNothingDeleted();
    }

    public function testThrowingSequenceEntryIsConsumed(): void
    {
        $exception = new RuntimeException('File download failed.');
        Files::fake([fn (): never => throw $exception, 'second-content']);

        try {
            Files::get('file_1');
            $this->fail('The first response must throw.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame('second-content', Files::get('file_2')->content);
    }
}
