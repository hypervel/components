<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\LazyCollection;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;
use SplFileInfo;

use function Hypervel\Coroutine\go;

class FilesystemTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = ParallelTesting::tempDir('FilesystemTest');

        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;
        $files->deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function testGetRetrievesFiles()
    {
        file_put_contents($this->tempDir . '/file.txt', 'Hello World');
        $files = new Filesystem;
        $this->assertSame('Hello World', $files->get($this->tempDir . '/file.txt'));
    }

    public function testPutStoresFiles()
    {
        $files = new Filesystem;
        $files->put($this->tempDir . '/file.txt', 'Hello World');
        $this->assertStringEqualsFile($this->tempDir . '/file.txt', 'Hello World');
    }

    public function testLines()
    {
        $path = $this->tempDir . '/file.txt';

        $contents = ' ' . PHP_EOL . ' spaces around ' . PHP_EOL . PHP_EOL . 'Line 2' . PHP_EOL . '1 trailing empty line ->' . PHP_EOL . PHP_EOL;
        file_put_contents($path, $contents);

        $files = new Filesystem;
        $this->assertInstanceOf(LazyCollection::class, $files->lines($path));

        $this->assertSame(
            [' ', ' spaces around ', '', 'Line 2', '1 trailing empty line ->', '', ''],
            $files->lines($path)->all()
        );

        // an empty file:
        ftruncate(fopen($path, 'w'), 0);
        $this->assertSame([''], $files->lines($path)->all());
    }

    public function testLinesThrowsExceptionNonexisitingFile()
    {
        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('File does not exist at path ' . __DIR__ . '/unknown-file.txt.');

        (new Filesystem)->lines(__DIR__ . '/unknown-file.txt');
    }

    public function testReplaceCreatesFile(): void
    {
        $tempFile = $this->tempDir . '/file.txt';

        $filesystem = new Filesystem;

        $filesystem->replace($tempFile, 'Hello World');
        $this->assertStringEqualsFile($tempFile, 'Hello World');
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testReplaceWritesContentsBeforeApplyingARestrictiveMode(): void
    {
        $path = $this->tempDir . '/read-only.txt';

        (new Filesystem)->replace($path, 'protected contents', 0444);

        $this->assertStringEqualsFile($path, 'protected contents');
        $this->assertSame(0444, $this->getFilePermissions($path));
    }

    public function testReplaceRejectsAMissingTargetDirectoryAndRemovesTheFallbackTempFile(): void
    {
        $prefix = 'hypervel-replace-' . getmypid() . '-missing-';
        $path = $this->tempDir . '/missing/' . $prefix;
        $before = $this->temporaryFilesWithPrefix($prefix);

        try {
            (new Filesystem)->replace($path, 'contents');
            $this->fail('Expected replacement into a missing directory to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("Unable to replace [{$path}] with temporary file", $exception->getMessage());
        }

        $this->assertFileDoesNotExist($path);
        $this->assertSame($before, $this->temporaryFilesWithPrefix($prefix));
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testReplaceRejectsAnUnwritableTargetDirectoryAndRemovesTheFallbackTempFile(): void
    {
        $directory = $this->tempDir . '/unwritable';
        mkdir($directory, 0555);

        if (is_writable($directory)) {
            chmod($directory, 0755);
            $this->markTestSkipped('The current user can write to directories without write permission.');
        }

        $prefix = 'hypervel-replace-' . getmypid() . '-unwritable-';
        $path = $directory . '/' . $prefix;
        $before = $this->temporaryFilesWithPrefix($prefix);

        try {
            (new Filesystem)->replace($path, 'contents');
            $this->fail('Expected replacement into an unwritable directory to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("Unable to replace [{$path}] with temporary file", $exception->getMessage());
        } finally {
            chmod($directory, 0755);
        }

        $this->assertFileDoesNotExist($path);
        $this->assertSame($before, $this->temporaryFilesWithPrefix($prefix));
    }

    public function testReplaceInFileCorrectlyReplaces()
    {
        $tempFile = $this->tempDir . '/file.txt';

        $filesystem = new Filesystem;

        $filesystem->put($tempFile, 'Hello World');
        $filesystem->replaceInFile('Hello World', 'Hello Taylor', $tempFile);
        $this->assertStringEqualsFile($tempFile, 'Hello Taylor');
    }

    public function testReplaceInFileFailsWhenTheSourceCannotBeRead(): void
    {
        $path = $this->tempDir . '/missing.txt';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to read file at path [{$path}].");

        (new Filesystem)->replaceInFile('old', 'new', $path);
    }

    public function testReplaceInFileFailsWhenTheReplacementIsOnlyPartiallyWritten(): void
    {
        FilesystemTestStreamWrapper::reset('Hello World');
        FilesystemTestStreamWrapper::$zeroWriteAfter = 5;
        stream_wrapper_register('hypervel-filesystem-test', FilesystemTestStreamWrapper::class);

        try {
            (new Filesystem)->replaceInFile('World', 'Taylor', 'hypervel-filesystem-test://file');
            $this->fail('A partial replacement write should fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Unable to write the complete contents of file', $exception->getMessage());
        } finally {
            stream_wrapper_unregister('hypervel-filesystem-test');
        }
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testReplaceWhenUnixSymlinkExists()
    {
        $tempFile = $this->tempDir . '/file.txt';
        $symlinkDir = $this->tempDir . '/symlink_dir';
        $symlink = "{$symlinkDir}/symlink.txt";

        mkdir($symlinkDir);
        symlink($tempFile, $symlink);

        // Prevent changes to symlink_dir
        chmod($symlinkDir, 0555);

        // Test with a weird non-standard umask.
        $umask = 0131;
        $originalUmask = umask($umask);

        $filesystem = new Filesystem;

        // Test replacing non-existent file.
        $filesystem->replace($tempFile, 'Hello World');
        $this->assertStringEqualsFile($tempFile, 'Hello World');
        $this->assertEquals($umask, 0777 - $this->getFilePermissions($tempFile));

        // Test replacing existing file.
        $filesystem->replace($tempFile, 'Something Else');
        $this->assertStringEqualsFile($tempFile, 'Something Else');
        $this->assertEquals($umask, 0777 - $this->getFilePermissions($tempFile));

        // Test replacing symlinked file.
        $filesystem->replace($symlink, 'Yet Something Else Again');
        $this->assertStringEqualsFile($tempFile, 'Yet Something Else Again');
        $this->assertEquals($umask, 0777 - $this->getFilePermissions($tempFile));

        umask($originalUmask);

        // Reset changes to symlink_dir
        chmod($symlinkDir, 0777 - $originalUmask);
    }

    public function testSetChmod()
    {
        file_put_contents($this->tempDir . '/file.txt', 'Hello World');
        $files = new Filesystem;
        $files->chmod($this->tempDir . '/file.txt', 0755);
        $filePermission = substr(sprintf('%o', fileperms($this->tempDir . '/file.txt')), -4);
        $expectedPermissions = DIRECTORY_SEPARATOR === '\\' ? '0666' : '0755';
        $this->assertEquals($expectedPermissions, $filePermission);
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testSetChmodAcceptsZeroMode(): void
    {
        $path = $this->tempDir . '/zero-mode.txt';
        file_put_contents($path, 'Hello World');

        try {
            $this->assertTrue((new Filesystem)->chmod($path, 0000));
            $this->assertSame(0, $this->getFilePermissions($path));
        } finally {
            chmod($path, 0600);
        }
    }

    public function testGetChmod()
    {
        file_put_contents($this->tempDir . '/file.txt', 'Hello World');
        chmod($this->tempDir . '/file.txt', 0755);

        $files = new Filesystem;
        $filePermission = $files->chmod($this->tempDir . '/file.txt');
        $expectedPermissions = DIRECTORY_SEPARATOR === '\\' ? '0666' : '0755';
        $this->assertEquals($expectedPermissions, $filePermission);
    }

    public function testGetChmodReturnsFalseWhenPermissionsCannotBeRead(): void
    {
        $this->assertFalse((new Filesystem)->chmod($this->tempDir . '/missing.txt'));
    }

    public function testDeleteRemovesFiles()
    {
        file_put_contents($this->tempDir . '/file1.txt', 'Hello World');
        file_put_contents($this->tempDir . '/file2.txt', 'Hello World');
        file_put_contents($this->tempDir . '/file3.txt', 'Hello World');

        $files = new Filesystem;
        $files->delete($this->tempDir . '/file1.txt');
        $this->assertFileDoesNotExist($this->tempDir . '/file1.txt');

        $files->delete([$this->tempDir . '/file2.txt', $this->tempDir . '/file3.txt']);
        $this->assertFileDoesNotExist($this->tempDir . '/file2.txt');
        $this->assertFileDoesNotExist($this->tempDir . '/file3.txt');
    }

    public function testPrependExistingFiles()
    {
        $files = new Filesystem;
        $files->put($this->tempDir . '/file.txt', 'World');
        $files->prepend($this->tempDir . '/file.txt', 'Hello ');
        $this->assertStringEqualsFile($this->tempDir . '/file.txt', 'Hello World');
    }

    public function testPrependNewFiles()
    {
        $files = new Filesystem;
        $files->prepend($this->tempDir . '/file.txt', 'Hello World');
        $this->assertStringEqualsFile($this->tempDir . '/file.txt', 'Hello World');
    }

    public function testMissingFile()
    {
        $files = new Filesystem;
        $this->assertTrue($files->missing($this->tempDir . '/file.txt'));
    }

    public function testDeleteDirectory()
    {
        mkdir($this->tempDir . '/foo');
        file_put_contents($this->tempDir . '/foo/file.txt', 'Hello World');
        $files = new Filesystem;
        $files->deleteDirectory($this->tempDir . '/foo');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/foo');
        $this->assertFileDoesNotExist($this->tempDir . '/foo/file.txt');
    }

    public function testDeleteDirectoryReturnFalseWhenNotADirectory()
    {
        mkdir($this->tempDir . '/bar');
        file_put_contents($this->tempDir . '/bar/file.txt', 'Hello World');
        $files = new Filesystem;
        $this->assertFalse($files->deleteDirectory($this->tempDir . '/bar/file.txt'));
    }

    public function testDeleteDirectoryReportsFailureAfterAttemptingEveryEntry(): void
    {
        $directory = $this->tempDir . '/exhaustive-delete';
        mkdir($directory);
        file_put_contents($directory . '/first.txt', 'first');
        file_put_contents($directory . '/second.txt', 'second');
        $filesystem = new RecordingDeleteFilesystem;

        $this->assertFalse($filesystem->deleteDirectory($directory));
        $this->assertSame(
            [$directory . '/first.txt', $directory . '/second.txt'],
            $filesystem->deleted
        );
    }

    public function testDeleteDirectoriesReportsFailureAfterAttemptingEveryDirectory(): void
    {
        $filesystem = new RecordingDirectoryDeleteFilesystem;

        $this->assertFalse($filesystem->deleteDirectories('/root'));
        $this->assertSame(['/root/first', '/root/second'], $filesystem->deleted);
    }

    public function testCleanDirectory()
    {
        mkdir($this->tempDir . '/baz');
        file_put_contents($this->tempDir . '/baz/file.txt', 'Hello World');
        $files = new Filesystem;
        $files->cleanDirectory($this->tempDir . '/baz');
        $this->assertDirectoryExists($this->tempDir . '/baz');
        $this->assertFileDoesNotExist($this->tempDir . '/baz/file.txt');
    }

    public function testMacro()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'Hello World');
        $files = new Filesystem;
        $tempDir = $this->tempDir;
        $files->macro('getFoo', function () use ($files, $tempDir) {
            return $files->get($tempDir . '/foo.txt');
        });
        $this->assertSame('Hello World', $files->getFoo());
    }

    public function testFilesMethod()
    {
        mkdir($this->tempDir . '/views');
        file_put_contents($this->tempDir . '/views/1.txt', '1');
        file_put_contents($this->tempDir . '/views/2.txt', '2');
        mkdir($this->tempDir . '/views/_layouts');
        $files = new Filesystem;
        $results = $files->files($this->tempDir . '/views');
        $this->assertInstanceOf(SplFileInfo::class, $results[0]);
        $this->assertInstanceOf(SplFileInfo::class, $results[1]);
        unset($files);
    }

    public function testCopyDirectoryReturnsFalseIfSourceIsntDirectory()
    {
        $files = new Filesystem;
        $this->assertFalse($files->copyDirectory($this->tempDir . '/breeze/boom/foo/bar/baz', $this->tempDir));
    }

    public function testCopyDirectoryMovesEntireDirectory()
    {
        mkdir($this->tempDir . '/tmp', 0777, true);
        file_put_contents($this->tempDir . '/tmp/foo.txt', '');
        file_put_contents($this->tempDir . '/tmp/bar.txt', '');
        mkdir($this->tempDir . '/tmp/nested', 0777, true);
        file_put_contents($this->tempDir . '/tmp/nested/baz.txt', '');

        $files = new Filesystem;
        $files->copyDirectory($this->tempDir . '/tmp', $this->tempDir . '/tmp2');
        $this->assertDirectoryExists($this->tempDir . '/tmp2');
        $this->assertFileExists($this->tempDir . '/tmp2/foo.txt');
        $this->assertFileExists($this->tempDir . '/tmp2/bar.txt');
        $this->assertDirectoryExists($this->tempDir . '/tmp2/nested');
        $this->assertFileExists($this->tempDir . '/tmp2/nested/baz.txt');
    }

    public function testMoveDirectoryMovesEntireDirectory()
    {
        mkdir($this->tempDir . '/tmp2', 0777, true);
        file_put_contents($this->tempDir . '/tmp2/foo.txt', '');
        file_put_contents($this->tempDir . '/tmp2/bar.txt', '');
        mkdir($this->tempDir . '/tmp2/nested', 0777, true);
        file_put_contents($this->tempDir . '/tmp2/nested/baz.txt', '');

        $files = new Filesystem;
        $files->moveDirectory($this->tempDir . '/tmp2', $this->tempDir . '/tmp3');
        $this->assertDirectoryExists($this->tempDir . '/tmp3');
        $this->assertFileExists($this->tempDir . '/tmp3/foo.txt');
        $this->assertFileExists($this->tempDir . '/tmp3/bar.txt');
        $this->assertDirectoryExists($this->tempDir . '/tmp3/nested');
        $this->assertFileExists($this->tempDir . '/tmp3/nested/baz.txt');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/tmp2');
    }

    public function testMoveDirectoryMovesEntireDirectoryAndOverwrites()
    {
        mkdir($this->tempDir . '/tmp4', 0777, true);
        file_put_contents($this->tempDir . '/tmp4/foo.txt', '');
        file_put_contents($this->tempDir . '/tmp4/bar.txt', '');
        mkdir($this->tempDir . '/tmp4/nested', 0777, true);
        file_put_contents($this->tempDir . '/tmp4/nested/baz.txt', '');
        mkdir($this->tempDir . '/tmp5', 0777, true);
        file_put_contents($this->tempDir . '/tmp5/foo2.txt', '');
        file_put_contents($this->tempDir . '/tmp5/bar2.txt', '');

        $files = new Filesystem;
        $files->moveDirectory($this->tempDir . '/tmp4', $this->tempDir . '/tmp5', true);
        $this->assertDirectoryExists($this->tempDir . '/tmp5');
        $this->assertFileExists($this->tempDir . '/tmp5/foo.txt');
        $this->assertFileExists($this->tempDir . '/tmp5/bar.txt');
        $this->assertDirectoryExists($this->tempDir . '/tmp5/nested');
        $this->assertFileExists($this->tempDir . '/tmp5/nested/baz.txt');
        $this->assertFileDoesNotExist($this->tempDir . '/tmp5/foo2.txt');
        $this->assertFileDoesNotExist($this->tempDir . '/tmp5/bar2.txt');
        $this->assertDirectoryDoesNotExist($this->tempDir . '/tmp4');
    }

    public function testMoveDirectoryReturnsFalseWhileOverwritingAndUnableToDeleteDestinationDirectory()
    {
        mkdir($this->tempDir . '/tmp6', 0777, true);
        file_put_contents($this->tempDir . '/tmp6/foo.txt', '');
        mkdir($this->tempDir . '/tmp7', 0777, true);

        $files = m::mock(Filesystem::class)->makePartial();
        $files->shouldReceive('deleteDirectory')->once()->andReturn(false);
        $this->assertFalse($files->moveDirectory($this->tempDir . '/tmp6', $this->tempDir . '/tmp7', true));
    }

    public function testGetThrowsExceptionNonexisitingFile()
    {
        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('File does not exist at path ' . $this->tempDir . '/unknown-file.txt.');

        (new Filesystem)->get($this->tempDir . '/unknown-file.txt');
    }

    public function testGetThrowsAFrameworkExceptionWhenTheFileDisappearsBeforeReading(): void
    {
        $path = $this->tempDir . '/vanished.txt';

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage("Unable to read file at path {$path}.");

        (new VanishingReadFilesystem)->get($path);
    }

    public function testSharedGetThrowsAFrameworkExceptionWhenTheFileDisappearsBeforeOpening(): void
    {
        $path = $this->tempDir . '/vanished.txt';

        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage("Unable to read file at path {$path}.");

        (new VanishingReadFilesystem)->get($path, true);
    }

    public function testGetRequireReturnsProperly()
    {
        file_put_contents($this->tempDir . '/file.php', '<?php return "Howdy?"; ?>');
        $files = new Filesystem;
        $this->assertSame('Howdy?', $files->getRequire($this->tempDir . '/file.php'));
    }

    public function testGetRequireThrowsExceptionNonExistingFile()
    {
        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('File does not exist at path ' . $this->tempDir . '/unknown-file.txt.');

        (new Filesystem)->getRequire($this->tempDir . '/unknown-file.txt');
    }

    public function testJsonReturnsDecodedJsonData()
    {
        file_put_contents($this->tempDir . '/file.json', '{"foo": "bar"}');
        $files = new Filesystem;
        $this->assertSame(['foo' => 'bar'], $files->json($this->tempDir . '/file.json'));
    }

    public function testJsonReturnsNullIfJsonDataIsInvalid()
    {
        file_put_contents($this->tempDir . '/file.json', '{"foo":');
        $files = new Filesystem;
        $this->assertNull($files->json($this->tempDir . '/file.json'));
    }

    public function testAppendAddsDataToFile()
    {
        file_put_contents($this->tempDir . '/file.txt', 'foo');
        $files = new Filesystem;
        $bytesWritten = $files->append($this->tempDir . '/file.txt', 'bar');
        $this->assertEquals(mb_strlen('bar', '8bit'), $bytesWritten);
        $this->assertFileExists($this->tempDir . '/file.txt');
        $this->assertStringEqualsFile($this->tempDir . '/file.txt', 'foobar');
    }

    public function testMoveMovesFiles()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'foo');
        $files = new Filesystem;
        $files->move($this->tempDir . '/foo.txt', $this->tempDir . '/bar.txt');
        $this->assertFileExists($this->tempDir . '/bar.txt');
        $this->assertFileDoesNotExist($this->tempDir . '/foo.txt');
    }

    public function testRelativeLinkThrowsWhenLinkCreationFails(): void
    {
        $filesystem = new StubLinkFilesystem(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create a relative link');

        $filesystem->relativeLink('/root/target.txt', '/root/link.txt');
    }

    public function testRelativeLinkAcceptsTheWindowsNullResult(): void
    {
        (new StubLinkFilesystem(null))->relativeLink('/root/target.txt', '/root/link.txt');

        $this->addToAssertionCount(1);
    }

    public function testNameReturnsName()
    {
        file_put_contents($this->tempDir . '/foobar.txt', 'foo');
        $filesystem = new Filesystem;
        $this->assertSame('foobar', $filesystem->name($this->tempDir . '/foobar.txt'));
    }

    public function testExtensionReturnsExtension()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'foo');
        $files = new Filesystem;
        $this->assertSame('txt', $files->extension($this->tempDir . '/foo.txt'));
    }

    public function testBasenameReturnsBasename()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'foo');
        $files = new Filesystem;
        $this->assertSame('foo.txt', $files->basename($this->tempDir . '/foo.txt'));
    }

    public function testDirnameReturnsDirectory()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'foo');
        $files = new Filesystem;
        $this->assertEquals($this->tempDir, $files->dirname($this->tempDir . '/foo.txt'));
    }

    public function testTypeIdentifiesFile()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'foo');
        $files = new Filesystem;
        $this->assertSame('file', $files->type($this->tempDir . '/foo.txt'));
    }

    public function testTypeIdentifiesDirectory()
    {
        mkdir($this->tempDir . '/foo-dir');
        $files = new Filesystem;
        $this->assertSame('dir', $files->type($this->tempDir . '/foo-dir'));
    }

    public function testNativeMetadataFailuresReturnFalse(): void
    {
        $path = $this->tempDir . '/missing.txt';
        $filesystem = new Filesystem;

        $this->assertFalse($filesystem->type($path));
        $this->assertFalse($filesystem->size($path));
        $this->assertFalse($filesystem->lastModified($path));
        $this->assertFalse($filesystem->hash($path));
        $this->assertFalse($filesystem->glob(str_repeat('a', 5000), GLOB_ERR));
    }

    public function testSizeOutputsSize()
    {
        $size = file_put_contents($this->tempDir . '/foo.txt', 'foo');
        $files = new Filesystem;
        $this->assertEquals($size, $files->size($this->tempDir . '/foo.txt'));
    }

    #[RequiresPhpExtension('fileinfo')]
    public function testMimeTypeOutputsMimeType()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'foo');
        $files = new Filesystem;
        $this->assertSame('text/plain', $files->mimeType($this->tempDir . '/foo.txt'));
    }

    public function testGuessExtensionReturnsNullWhenMimeDetectionFails(): void
    {
        $this->assertNull((new MissingMimeTypeFilesystem)->guessExtension('/missing.txt'));
    }

    public function testIsWritable()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Permission checks are unreliable when running as root.');
        }

        file_put_contents($this->tempDir . '/foo.txt', 'foo');
        $files = new Filesystem;
        @chmod($this->tempDir . '/foo.txt', 0444);
        $this->assertFalse($files->isWritable($this->tempDir . '/foo.txt'));
        @chmod($this->tempDir . '/foo.txt', 0777);
        $this->assertTrue($files->isWritable($this->tempDir . '/foo.txt'));
    }

    public function testIsReadable()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'foo');
        $files = new Filesystem;
        // chmod is noneffective on Windows
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->assertTrue($files->isReadable($this->tempDir . '/foo.txt'));
        } elseif (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Permission checks are unreliable when running as root.');
        } else {
            @chmod($this->tempDir . '/foo.txt', 0000);
            $this->assertFalse($files->isReadable($this->tempDir . '/foo.txt'));
            @chmod($this->tempDir . '/foo.txt', 0777);
            $this->assertTrue($files->isReadable($this->tempDir . '/foo.txt'));
        }
        $this->assertFalse($files->isReadable($this->tempDir . '/doesnotexist.txt'));
    }

    public function testIsDirEmpty()
    {
        mkdir($this->tempDir . '/foo-dir');
        file_put_contents($this->tempDir . '/foo-dir/.hidden', 'foo');
        mkdir($this->tempDir . '/bar-dir');
        file_put_contents($this->tempDir . '/bar-dir/foo.txt', 'foo');
        mkdir($this->tempDir . '/baz-dir');
        mkdir($this->tempDir . '/baz-dir/.hidden');
        mkdir($this->tempDir . '/quz-dir');
        mkdir($this->tempDir . '/quz-dir/not-hidden');

        $files = new Filesystem;

        $this->assertTrue($files->isEmptyDirectory($this->tempDir . '/foo-dir', true));
        $this->assertFalse($files->isEmptyDirectory($this->tempDir . '/foo-dir'));
        $this->assertFalse($files->isEmptyDirectory($this->tempDir . '/bar-dir', true));
        $this->assertFalse($files->isEmptyDirectory($this->tempDir . '/bar-dir'));
        $this->assertTrue($files->isEmptyDirectory($this->tempDir . '/baz-dir', true));
        $this->assertFalse($files->isEmptyDirectory($this->tempDir . '/baz-dir'));
        $this->assertFalse($files->isEmptyDirectory($this->tempDir . '/quz-dir', true));
        $this->assertFalse($files->isEmptyDirectory($this->tempDir . '/quz-dir'));
    }

    public function testGlobFindsFiles()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'foo');
        file_put_contents($this->tempDir . '/bar.txt', 'bar');
        $files = new Filesystem;
        $glob = $files->glob($this->tempDir . '/*.txt');
        $this->assertContains($this->tempDir . '/foo.txt', $glob);
        $this->assertContains($this->tempDir . '/bar.txt', $glob);
    }

    public function testAllFilesFindsFiles()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'foo');
        file_put_contents($this->tempDir . '/bar.txt', 'bar');
        $files = new Filesystem;
        $allFiles = [];
        foreach ($files->allFiles($this->tempDir) as $file) {
            $allFiles[] = $file->getFilename();
        }
        $this->assertContains('foo.txt', $allFiles);
        $this->assertContains('bar.txt', $allFiles);
    }

    public function testDirectoriesFindsDirectories()
    {
        mkdir($this->tempDir . '/film');
        mkdir($this->tempDir . '/music');
        $files = new Filesystem;
        $directories = $files->directories($this->tempDir);
        $this->assertContains($this->tempDir . DIRECTORY_SEPARATOR . 'film', $directories);
        $this->assertContains($this->tempDir . DIRECTORY_SEPARATOR . 'music', $directories);
    }

    public function testAllDirectoriesFindsDirectories()
    {
        mkdir($this->tempDir . '/film');
        mkdir($this->tempDir . '/music');
        mkdir($this->tempDir . '/music/rock');
        mkdir($this->tempDir . '/music/blues');

        $directories = (new Filesystem)->allDirectories($this->tempDir);

        $this->assertContains($this->tempDir . DIRECTORY_SEPARATOR . 'film', $directories);
        $this->assertContains($this->tempDir . DIRECTORY_SEPARATOR . 'music', $directories);
        $this->assertContains($this->tempDir . DIRECTORY_SEPARATOR . 'music' . DIRECTORY_SEPARATOR . 'rock', $directories);
        $this->assertContains($this->tempDir . DIRECTORY_SEPARATOR . 'music' . DIRECTORY_SEPARATOR . 'blues', $directories);
    }

    public function testMakeDirectory()
    {
        $files = new Filesystem;
        $this->assertTrue($files->makeDirectory($this->tempDir . '/created'));
        $this->assertFileExists($this->tempDir . '/created');
    }

    public function testEnsureDirectoryExistsThrowsWhenCreationFails(): void
    {
        $path = $this->tempDir . '/uncreatable';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to create directory [{$path}].");

        (new FailingDirectoryFilesystem)->ensureDirectoryExists($path);
    }

    public function testEnsureDirectoryExistsAcceptsAConcurrentCreator(): void
    {
        $filesystem = new ConcurrentDirectoryFilesystem;

        $filesystem->ensureDirectoryExists($this->tempDir . '/concurrently-created');

        $this->assertSame(2, $filesystem->directoryChecks);
        $this->assertSame(1, $filesystem->creationAttempts);
    }

    public function testRequireOnceRequiresFileProperly()
    {
        $filesystem = new Filesystem;
        mkdir($this->tempDir . '/scripts');
        file_put_contents($this->tempDir . '/scripts/foo.php', '<?php function random_function_xyz(){};');
        $filesystem->requireOnce($this->tempDir . '/scripts/foo.php');
        file_put_contents($this->tempDir . '/scripts/foo.php', '<?php function random_function_xyz_changed(){};');
        $filesystem->requireOnce($this->tempDir . '/scripts/foo.php');
        $this->assertTrue(function_exists('random_function_xyz'));
        $this->assertFalse(function_exists('random_function_xyz_changed'));
    }

    public function testRequireOnceThrowsExceptionNonexisitingFile()
    {
        $this->expectException(FileNotFoundException::class);
        $this->expectExceptionMessage('File does not exist at path ' . __DIR__ . '/unknown-file.txt.');

        (new Filesystem)->requireOnce(__DIR__ . '/unknown-file.txt');
    }

    public function testCopyCopiesFileProperly()
    {
        $filesystem = new Filesystem;
        $data = 'contents';
        mkdir($this->tempDir . '/text');
        file_put_contents($this->tempDir . '/text/foo.txt', $data);
        $filesystem->copy($this->tempDir . '/text/foo.txt', $this->tempDir . '/text/foo2.txt');
        $this->assertFileExists($this->tempDir . '/text/foo2.txt');
        $this->assertEquals($data, file_get_contents($this->tempDir . '/text/foo2.txt'));
    }

    public function testHasSameHashChecksFileHashes()
    {
        $filesystem = new Filesystem;

        mkdir($this->tempDir . '/text');
        file_put_contents($this->tempDir . '/text/foo.txt', 'contents');
        file_put_contents($this->tempDir . '/text/foo2.txt', 'contents');
        file_put_contents($this->tempDir . '/text/foo3.txt', 'invalid');

        $this->assertTrue($filesystem->hasSameHash($this->tempDir . '/text/foo.txt', $this->tempDir . '/text/foo2.txt'));
        $this->assertFalse($filesystem->hasSameHash($this->tempDir . '/text/foo.txt', $this->tempDir . '/text/foo3.txt'));
        $this->assertFalse($filesystem->hasSameHash($this->tempDir . '/text/foo4.txt', $this->tempDir . '/text/foo.txt'));
        $this->assertFalse($filesystem->hasSameHash($this->tempDir . '/text/foo.txt', $this->tempDir . '/text/foo4.txt'));
    }

    public function testIsFileChecksFilesProperly()
    {
        $filesystem = new Filesystem;
        mkdir($this->tempDir . '/help');
        file_put_contents($this->tempDir . '/help/foo.txt', 'contents');
        $this->assertTrue($filesystem->isFile($this->tempDir . '/help/foo.txt'));
        $this->assertFalse($filesystem->isFile($this->tempDir . './help'));
    }

    public function testFilesMethodReturnsFileInfoObjects()
    {
        mkdir($this->tempDir . '/objects');
        file_put_contents($this->tempDir . '/objects/1.txt', '1');
        file_put_contents($this->tempDir . '/objects/2.txt', '2');
        mkdir($this->tempDir . '/objects/bar');
        $files = new Filesystem;
        $this->assertContainsOnlyInstancesOf(SplFileInfo::class, $files->files($this->tempDir . '/objects'));
        unset($files);
    }

    public function testAllFilesReturnsFileInfoObjects()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'foo');
        file_put_contents($this->tempDir . '/bar.txt', 'bar');
        $files = new Filesystem;
        $this->assertContainsOnlyInstancesOf(SplFileInfo::class, $files->allFiles($this->tempDir));
    }

    public function testHashWithDefaultValue()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'foo');
        $filesystem = new Filesystem;
        $this->assertSame('79aef92e83454121ab6e5f64077e7d8a', $filesystem->hash($this->tempDir . '/foo.txt'));
    }

    public function testHash()
    {
        file_put_contents($this->tempDir . '/foo.txt', 'foo');
        $filesystem = new Filesystem;
        $this->assertSame('acbd18db4cc2f85cedef654fccc4a4d8', $filesystem->hash($this->tempDir . '/foo.txt', 'md5'));
        $this->assertSame('0beec7b5ea3f0fdbc95d0dd47f3c5bc275da8a33', $filesystem->hash($this->tempDir . '/foo.txt', 'sha1'));
        $this->assertSame('76d3bc41c9f588f7fcd0d5bf4718f8f84b1c41b20882703100b9eb9413807c01', $filesystem->hash($this->tempDir . '/foo.txt', 'sha3-256'));
    }

    public function testLastModifiedReturnsTimestamp()
    {
        $path = $this->tempDir . '/timestamp.txt';
        file_put_contents($path, 'test content');

        $filesystem = new Filesystem;
        $timestamp = $filesystem->lastModified($path);

        $this->assertIsInt($timestamp);
        $this->assertGreaterThan(0, $timestamp);
        $this->assertEquals(filemtime($path), $timestamp);
    }

    public function testFileCreationAndContentVerification()
    {
        $files = new Filesystem;

        $testContent = 'This is a test file content';
        $filePath = $this->tempDir . '/test.txt';

        $files->put($filePath, $testContent);

        $this->assertTrue($files->exists($filePath));
        $this->assertSame($testContent, $files->get($filePath));
        $this->assertEquals(strlen($testContent), $files->size($filePath));
    }

    public function testDirectoryOperationsWithSubdirectories()
    {
        $files = new Filesystem;

        $dirPath = $this->tempDir . '/test_dir';
        $subDirPath = $dirPath . '/sub_dir';

        $this->assertTrue($files->makeDirectory($dirPath));
        $this->assertTrue($files->isDirectory($dirPath));

        $this->assertTrue($files->makeDirectory($subDirPath));
        $this->assertTrue($files->isDirectory($subDirPath));

        $filePath = $subDirPath . '/test.txt';
        $files->put($filePath, 'test content');

        $this->assertTrue($files->exists($filePath));

        $allFiles = $files->allFiles($dirPath);

        $this->assertCount(1, $allFiles);
        $this->assertEquals('test.txt', $allFiles[0]->getFilename());
    }

    public function testConcurrentCoroutineSharedGetAndLockedPut()
    {
        $files = new Filesystem;
        $path = $this->tempDir . '/concurrent.txt';
        $content = str_repeat('abcdef', 10000); // 60 KiB

        $results = [];
        $channel = new \Hypervel\Engine\Channel(10);

        for ($i = 0; $i < 10; ++$i) {
            go(function () use ($files, $path, $content, $channel) {
                $files->put($path, $content, true);
                $read = $files->get($path, true);
                $channel->push(strlen($read) === strlen($content));
            });
        }

        for ($i = 0; $i < 10; ++$i) {
            $results[] = $channel->pop(5.0);
        }

        // All coroutines should have read the complete content — no partial reads
        $this->assertCount(10, $results);
        foreach ($results as $index => $result) {
            $this->assertTrue($result, "Coroutine {$index} got a partial or corrupt read");
        }
    }

    private function getFilePermissions(string $file): int
    {
        $filePerms = fileperms($file);
        $filePerms = substr(sprintf('%o', $filePerms), -3);

        return (int) base_convert($filePerms, 8, 10);
    }

    /**
     * Get system temporary files beginning with the given prefix.
     *
     * @return list<string>
     */
    private function temporaryFilesWithPrefix(string $prefix): array
    {
        $files = glob(sys_get_temp_dir() . '/' . $prefix . '*') ?: [];
        sort($files);

        return $files;
    }
}

class VanishingReadFilesystem extends Filesystem
{
    /**
     * Simulate a file disappearing after the initial metadata check.
     */
    public function isFile(string $file): bool
    {
        return true;
    }
}

class FailingDirectoryFilesystem extends Filesystem
{
    public function isDirectory(string $directory): bool
    {
        return false;
    }

    public function makeDirectory(string $path, int $mode = 0755, bool $recursive = false, bool $force = false): bool
    {
        return false;
    }
}

class ConcurrentDirectoryFilesystem extends Filesystem
{
    public int $directoryChecks = 0;

    public int $creationAttempts = 0;

    public function isDirectory(string $directory): bool
    {
        return ++$this->directoryChecks > 1;
    }

    public function makeDirectory(string $path, int $mode = 0755, bool $recursive = false, bool $force = false): bool
    {
        ++$this->creationAttempts;

        return false;
    }
}

class RecordingDeleteFilesystem extends Filesystem
{
    /** @var list<string> */
    public array $deleted = [];

    public function delete($paths): bool
    {
        $this->deleted[] = $paths;

        return ! str_ends_with($paths, '/first.txt');
    }
}

class RecordingDirectoryDeleteFilesystem extends Filesystem
{
    /** @var list<string> */
    public array $deleted = [];

    public function directories(array|string $directory, array|string|int $depth = 0): array
    {
        return ['/root/first', '/root/second'];
    }

    public function deleteDirectory(string $directory, bool $preserve = false): bool
    {
        $this->deleted[] = $directory;

        return $directory !== '/root/first';
    }
}

class StubLinkFilesystem extends Filesystem
{
    public function __construct(private ?bool $result)
    {
    }

    public function link(string $target, string $link): ?bool
    {
        return $this->result;
    }

    public function isFile(string $file): bool
    {
        return true;
    }
}

class MissingMimeTypeFilesystem extends Filesystem
{
    public function mimeType(string $path): string|false
    {
        return false;
    }
}

class FilesystemTestStreamWrapper
{
    public mixed $context;

    public static string $contents = '';

    public static ?int $zeroWriteAfter = null;

    private int $position = 0;

    public static function reset(string $contents): void
    {
        self::$contents = $contents;
        self::$zeroWriteAfter = null;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (str_contains($mode, 'w')) {
            self::$contents = '';
        }

        return true;
    }

    public function stream_read(int $count): string
    {
        $contents = substr(self::$contents, $this->position, $count);
        $this->position += strlen($contents);

        return $contents;
    }

    public function stream_write(string $data): int
    {
        if (self::$zeroWriteAfter !== null && $this->position >= self::$zeroWriteAfter) {
            return 0;
        }

        $length = self::$zeroWriteAfter === null
            ? strlen($data)
            : min(strlen($data), self::$zeroWriteAfter - $this->position);

        self::$contents = substr_replace(self::$contents, substr($data, 0, $length), $this->position, $length);
        $this->position += $length;

        return $length;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$contents);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen(self::$contents) + $offset,
        };

        if ($position < 0) {
            return false;
        }

        $this->position = $position;

        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_stat(): array
    {
        return ['size' => strlen(self::$contents)];
    }
}
