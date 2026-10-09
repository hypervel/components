<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Tools\FileStorage;
use Hypervel\Ai\Tools\Filesystem\CopyFile;
use Hypervel\Ai\Tools\Filesystem\DeleteFile;
use Hypervel\Ai\Tools\Filesystem\FileExists;
use Hypervel\Ai\Tools\Filesystem\GetFileMetadata;
use Hypervel\Ai\Tools\Filesystem\GetFileUrl;
use Hypervel\Ai\Tools\Filesystem\ListFiles;
use Hypervel\Ai\Tools\Filesystem\MoveFile;
use Hypervel\Ai\Tools\Filesystem\ReadFile;
use Hypervel\Ai\Tools\Filesystem\WriteFile;
use Hypervel\Ai\Tools\Request;
use Hypervel\Ai\Tools\ToolNameResolver;
use Hypervel\Container\Container;
use Hypervel\Contracts\Filesystem\Filesystem;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Http\UploadedFile;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Facades\Storage;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Ai\agent;
use function Hypervel\Tests\Ai\Fixtures\fakeOpenAiResponse;

require_once __DIR__ . '/../Fixtures/helpers.php';

class FilesystemToolsTest extends TestCase
{
    /**
     * Create an isolated local disk for each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function testResolvedFilesystemToolsDoNotShareApprovalRequirements(): void
    {
        $first = Container::getInstance()->make(ReadFile::class)->requireApproval('Review this file.');
        $second = Container::getInstance()->make(ReadFile::class);

        $this->assertNotNull($first->shouldRequestApproval(new Request));
        $this->assertNull($second->shouldRequestApproval(new Request));
    }

    #[DataProvider('cancellationPaths')]
    public function testFilesystemCancellationIsNotConvertedToAToolResult(string $toolClass, string $method): void
    {
        $cancellation = new CanceledException('Download canceled.');
        $disk = m::mock(FilesystemAdapter::class);
        $disk->shouldReceive('fileExists')->andReturn(true);
        $disk->shouldReceive('size')->andReturn(10)->byDefault();
        $disk->shouldReceive($method)->once()->andThrow($cancellation);

        $this->expectExceptionObject($cancellation);

        (new $toolClass($disk))->handle(new Request(['path' => 'file.txt', 'from' => 'file.txt', 'to' => 'copy.txt']));
    }

    /**
     * Provide filesystem operations whose ordinary errors become tool results.
     */
    public static function cancellationPaths(): array
    {
        return [
            'copy' => [CopyFile::class, 'copy'],
            'move' => [MoveFile::class, 'move'],
            'read metadata' => [ReadFile::class, 'size'],
            'metadata size' => [GetFileMetadata::class, 'size'],
            'optional metadata' => [GetFileMetadata::class, 'lastModified'],
            'URL' => [GetFileUrl::class, 'url'],
        ];
    }

    public function testListFilesReturnsFilesAndDirectoriesUnderAPath(): void
    {
        Storage::disk('local')->put('docs/a.txt', 'A');
        Storage::disk('local')->put('docs/nested/b.txt', 'B');
        Storage::disk('local')->put('root.txt', 'R');

        $result = (new ListFiles('local'))->handle(new Request(['path' => 'docs']));

        $this->assertStringContainsString('docs/a.txt', $result);
        $this->assertStringContainsString('docs/nested', $result);
        $this->assertStringNotContainsString('root.txt', $result);
    }

    public function testListFilesCanRecurseIntoSubdirectories(): void
    {
        Storage::disk('local')->put('docs/a.txt', 'A');
        Storage::disk('local')->put('docs/nested/b.txt', 'B');

        $result = (new ListFiles('local'))->handle(new Request(['path' => 'docs', 'recursive' => true]));

        $this->assertStringContainsString('docs/a.txt', $result);
        $this->assertStringContainsString('docs/nested/b.txt', $result);
    }

    public function testReadFileReturnsTextContents(): void
    {
        Storage::disk('local')->put('note.txt', 'Hello world');

        $result = (new ReadFile('local'))->handle(new Request(['path' => 'note.txt']));

        $this->assertSame('Hello world', $result);
    }

    public function testReadFileReportsAMissingFile(): void
    {
        $result = (new ReadFile('local'))->handle(new Request(['path' => 'missing.txt']));

        $this->assertSame('File [missing.txt] does not exist.', $result);
    }

    public function testReadFileRejectsAnOversizedFile(): void
    {
        Storage::disk('local')->put('big.txt', str_repeat('a', 300 * 1024));

        $result = (new ReadFile('local'))->handle(new Request(['path' => 'big.txt']));

        $this->assertStringContainsString('too large to read inline', $result);
    }

    public function testReadFileRejectsABinaryFile(): void
    {
        Storage::disk('local')->put('image.bin', "\xff\xfe\x00\x01binary");

        $result = (new ReadFile('local'))->handle(new Request(['path' => 'image.bin']));

        $this->assertStringContainsString('appears to be binary', $result);
    }

    public function testFileExistsReportsPresenceAndAbsence(): void
    {
        Storage::disk('local')->put('there.txt', 'x');

        $this->assertSame('File [there.txt] exists.', (new FileExists('local'))->handle(new Request(['path' => 'there.txt'])));

        $this->assertSame('File [nope.txt] does not exist.', (new FileExists('local'))->handle(new Request(['path' => 'nope.txt'])));
    }

    public function testFileExistsDoesNotReportDirectoriesAsFiles(): void
    {
        Storage::disk('local')->makeDirectory('docs');

        $result = (new FileExists('local'))->handle(new Request(['path' => 'docs']));

        $this->assertSame('File [docs] does not exist.', $result);
    }

    public function testFileMetadataReturnsSizeAndMimeType(): void
    {
        Storage::disk('local')->put('data.txt', 'twelve bytes');

        $metadata = json_decode((new GetFileMetadata('local'))->handle(new Request(['path' => 'data.txt'])), true);

        $this->assertSame(12, $metadata['size']);
        $this->assertArrayHasKey('mime_type', $metadata);
        $this->assertArrayHasKey('last_modified', $metadata);
        $this->assertArrayHasKey('visibility', $metadata);
    }

    public function testFileMetadataReportsAMissingFile(): void
    {
        $result = (new GetFileMetadata('local'))->handle(new Request(['path' => 'missing.txt']));

        $this->assertSame('File [missing.txt] does not exist.', $result);
    }

    public function testFileUrlReturnsAUsableStringAndNeverThrows(): void
    {
        Storage::disk('local')->put('pic.txt', 'x');

        $this->assertStringContainsString('pic.txt', (new GetFileUrl('local'))->handle(new Request(['path' => 'pic.txt'])));

        $this->assertStringContainsString('pic.txt', (new GetFileUrl('local'))->handle(new Request(['path' => 'pic.txt', 'expires_in_minutes' => 5])));
    }

    public function testFileUrlReportsAMissingFile(): void
    {
        $result = (new GetFileUrl('local'))->handle(new Request(['path' => 'missing.txt']));

        $this->assertSame('File [missing.txt] does not exist.', $result);
    }

    public function testFileUrlDoesNotGenerateUrlsForDirectories(): void
    {
        Storage::disk('local')->makeDirectory('docs');

        $result = (new GetFileUrl('local'))->handle(new Request(['path' => 'docs']));

        $this->assertSame('File [docs] does not exist.', $result);
    }

    public function testWriteFileCreatesAFile(): void
    {
        $result = (new WriteFile('local'))->handle(new Request(['path' => 'out.txt', 'contents' => 'written']));

        $this->assertStringContainsString('Wrote', $result);
        Storage::disk('local')->assertExists('out.txt');
        $this->assertSame('written', Storage::disk('local')->get('out.txt'));
    }

    public function testWriteFileReportsWriteFailures(): void
    {
        Storage::disk('local')->makeDirectory('out.txt');

        $result = (new WriteFile('local'))->handle(new Request(['path' => 'out.txt', 'contents' => 'written']));

        $this->assertSame('Unable to write [out.txt].', $result);
    }

    public function testDeleteFileRemovesAFile(): void
    {
        Storage::disk('local')->put('gone.txt', 'x');

        $result = (new DeleteFile('local'))->handle(new Request(['path' => 'gone.txt']));

        $this->assertSame('Deleted [gone.txt].', $result);
        Storage::disk('local')->assertMissing('gone.txt');
    }

    public function testDeleteFileReportsAMissingFile(): void
    {
        $result = (new DeleteFile('local'))->handle(new Request(['path' => 'missing.txt']));

        $this->assertSame('File [missing.txt] does not exist.', $result);
    }

    public function testDeleteFileDoesNotReportDirectoriesAsFiles(): void
    {
        Storage::disk('local')->makeDirectory('docs');

        $result = (new DeleteFile('local'))->handle(new Request(['path' => 'docs']));

        $this->assertSame('File [docs] does not exist.', $result);
        Storage::disk('local')->assertExists('docs');
    }

    public function testDeleteFileReportsDeleteFailures(): void
    {
        $disk = m::mock(Filesystem::class);
        $disk->shouldReceive('fileExists')->with('gone.txt')->andReturn(true);
        $disk->shouldReceive('delete')->with('gone.txt')->andReturn(false);

        $result = (new DeleteFile($disk))->handle(new Request(['path' => 'gone.txt']));

        $this->assertSame('Unable to delete [gone.txt].', $result);
    }

    public function testCopyFileDuplicatesAFile(): void
    {
        Storage::disk('local')->put('src.txt', 'data');

        $result = (new CopyFile('local'))->handle(new Request(['from' => 'src.txt', 'to' => 'dst.txt']));

        $this->assertSame('Copied [src.txt] to [dst.txt].', $result);
        Storage::disk('local')->assertExists('dst.txt');
    }

    public function testCopyFileReportsAMissingSource(): void
    {
        $result = (new CopyFile('local'))->handle(new Request(['from' => 'missing.txt', 'to' => 'dst.txt']));

        $this->assertSame('Unable to copy [missing.txt] to [dst.txt]. The source file may not exist.', $result);
    }

    public function testMoveFileRelocatesAFile(): void
    {
        Storage::disk('local')->put('src.txt', 'data');

        $result = (new MoveFile('local'))->handle(new Request(['from' => 'src.txt', 'to' => 'dst.txt']));

        $this->assertSame('Moved [src.txt] to [dst.txt].', $result);
        Storage::disk('local')->assertMissing('src.txt');
        Storage::disk('local')->assertExists('dst.txt');
    }

    public function testMoveFileReportsAMissingSource(): void
    {
        $result = (new MoveFile('local'))->handle(new Request(['from' => 'missing.txt', 'to' => 'dst.txt']));

        $this->assertSame('File [missing.txt] does not exist.', $result);
    }

    public function testMoveFileDoesNotMoveDirectories(): void
    {
        Storage::disk('local')->makeDirectory('photos');

        $result = (new MoveFile('local'))->handle(new Request(['from' => 'photos', 'to' => 'archive/photos']));

        $this->assertSame('File [photos] does not exist.', $result);
        Storage::disk('local')->assertExists('photos');
        Storage::disk('local')->assertMissing('archive/photos');
    }

    public function testMoveFileReportsMoveFailures(): void
    {
        $disk = m::mock(Filesystem::class);
        $disk->shouldReceive('fileExists')->with('a.txt')->andReturn(true);
        $disk->shouldReceive('move')->with('a.txt', 'b.txt')->andReturn(false);

        $result = (new MoveFile($disk))->handle(new Request(['from' => 'a.txt', 'to' => 'b.txt']));

        $this->assertSame('Unable to move [a.txt] to [b.txt].', $result);
    }

    public function testFileStorageToolsAllReturnsEveryToolAsACollection(): void
    {
        $tools = FileStorage::all('local');

        $this->assertInstanceOf(Collection::class, $tools);
        $this->assertCount(9, $tools);
        $this->assertTrue($tools->contains(fn (Tool $tool): bool => $tool instanceof WriteFile));
    }

    public function testFileStorageToolsCanBeFilteredAsACollection(): void
    {
        $tools = FileStorage::all('local')
            ->reject(fn (Tool $tool): bool => $tool instanceof DeleteFile);

        $this->assertCount(8, $tools);
        $this->assertFalse($tools->contains(fn (Tool $tool): bool => $tool instanceof DeleteFile));
    }

    public function testFileStorageToolsReadOnlyReturnsOnlyReadTools(): void
    {
        $tools = FileStorage::readOnly('local');

        $this->assertInstanceOf(Collection::class, $tools);
        $this->assertCount(5, $tools);
        $this->assertTrue($tools->contains(fn (Tool $tool): bool => $tool instanceof ReadFile));
        $this->assertFalse($tools->contains(fn (Tool $tool): bool => $tool instanceof WriteFile));
        $this->assertFalse($tools->contains(fn (Tool $tool): bool => $tool instanceof DeleteFile));
        $this->assertFalse($tools->contains(fn (Tool $tool): bool => $tool instanceof CopyFile));
    }

    public function testFilesystemToolNamesResolveToClassBasenames(): void
    {
        $this->assertSame('ReadFile', ToolNameResolver::resolve(new ReadFile('local')));
        $this->assertSame('ListFiles', ToolNameResolver::resolve(new ListFiles('local')));
        $this->assertSame('WriteFile', ToolNameResolver::resolve(new WriteFile('local')));
    }

    public function testFilesystemToolSchemasBuild(): void
    {
        $schema = (new CopyFile('local'))->schema(new JsonSchemaTypeFactory);

        $this->assertArrayHasKey('from', $schema);
        $this->assertArrayHasKey('to', $schema);
    }

    public function testEveryFilesystemToolMapsToAStrictCompliantOpenAiSchema(): void
    {
        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'key' => 'test-key',
        ]]);

        Http::fake(['*' => fakeOpenAiResponse('ok')]);

        agent(tools: FileStorage::all('local'))
            ->prompt('List the files', provider: 'openai');

        Http::assertSent(function (HttpRequest $request): bool {
            $tools = collect(data_get(json_decode($request->body(), true), 'tools'))->where('type', 'function');

            if ($tools->count() !== 9) {
                return false;
            }

            foreach ($tools as $tool) {
                $properties = array_keys($tool['parameters']['properties'] ?? []);

                if (($tool['strict'] ?? false) !== true
                    || array_diff($properties, $tool['parameters']['required'] ?? [])
                    || ($tool['parameters']['additionalProperties'] ?? null) !== false) {
                    return false;
                }
            }

            return true;
        });
    }

    public function testAgentCopiesAFileEndToEnd(): void
    {
        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'key' => 'test-key',
        ]]);

        Storage::disk('local')->putFileAs('photos', UploadedFile::fake()->image('photo1.jpg'), 'photo1.jpg');

        Http::fake([
            'api.openai.com/*' => Http::sequence([
                fakeOpenAiFileToolCall('CopyFile', ['from' => 'photos/photo1.jpg', 'to' => 'wallpapers/photo1.jpg']),
                fakeOpenAiResponse('Done'),
            ]),
        ]);

        (new FileStorageAgent)->prompt('Copy photo1 into the wallpapers folder', provider: 'openai');

        $this->assertCount(2, Http::recorded());

        Storage::disk('local')->assertExists(['photos/photo1.jpg', 'wallpapers/photo1.jpg']);
        Storage::disk('local')->assertCount('wallpapers', 1);
    }

    public function testAgentDeletesAFileEndToEnd(): void
    {
        config(['ai.providers.openai' => [
            ...config('ai.providers.openai'),
            'key' => 'test-key',
        ]]);

        Storage::disk('local')->putFileAs('photos', UploadedFile::fake()->image('photo1.jpg'), 'photo1.jpg');
        Storage::disk('local')->assertExists('photos/photo1.jpg');

        Http::fake([
            'api.openai.com/*' => Http::sequence([
                fakeOpenAiFileToolCall('DeleteFile', ['path' => 'photos/photo1.jpg']),
                fakeOpenAiResponse('Deleted'),
            ]),
        ]);

        (new FileStorageAgent)->prompt('Delete photo1', provider: 'openai');

        Storage::disk('local')->assertMissing('photos/photo1.jpg');
        Storage::disk('local')->assertDirectoryEmpty('photos');
    }
}

function fakeOpenAiFileToolCall(string $name, array $arguments): PromiseInterface
{
    $id = uniqid();

    return Http::response([
        'id' => 'resp_tool_' . $id,
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [[
            'type' => 'function_call',
            'id' => 'fc_' . $id,
            'call_id' => 'call_' . $id,
            'name' => $name,
            'arguments' => json_encode($arguments),
            'status' => 'completed',
        ]],
        'usage' => [
            'input_tokens' => 10,
            'output_tokens' => 5,
        ],
    ]);
}

class FileStorageAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Get the agent instructions.
     */
    public function instructions(): string
    {
        return 'You manage files on disk using the available tools.';
    }

    /**
     * Get the available filesystem tools.
     */
    public function tools(): iterable
    {
        return FileStorage::all('local');
    }
}
