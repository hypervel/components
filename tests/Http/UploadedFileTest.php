<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Context\ApplicationContext;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Http\Exceptions\CannotWriteFileException;
use Hypervel\Http\Exceptions\ExtensionFileException;
use Hypervel\Http\Exceptions\FileException;
use Hypervel\Http\Exceptions\FileNotFoundException;
use Hypervel\Http\Exceptions\FormSizeFileException;
use Hypervel\Http\Exceptions\IniSizeFileException;
use Hypervel\Http\Exceptions\NoFileException;
use Hypervel\Http\Exceptions\NoTmpDirFileException;
use Hypervel\Http\Exceptions\PartialFileException;
use Hypervel\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class UploadedFileTest extends TestCase
{
    public function setUp(): void
    {
        $container = new Container(
            new DefinitionSource([])
        );

        ApplicationContext::setContainer($container);
    }

    public function testUploadedFileCanRetrieveContentsFromTextFile()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.txt',
            'test.txt',
            null,
            null,
            null,
            true
        );

        $this->assertSame('This is a story about something that happened long ago when your grandfather was a child.', trim($file->get()));
    }

    public function testConstructWhenFileNotExists()
    {
        $this->expectException(FileNotFoundException::class);

        new UploadedFile(
            __DIR__ . '/fixtures/not_here',
            'original.gif',
            null
        );
    }

    public function testFileUploadsWithNoMimeType()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'original.gif',
            null,
            UPLOAD_ERR_OK
        );

        $this->assertEquals('application/octet-stream', $file->getClientMimeType());

        if (! extension_loaded('fileinfo')) {
            $this->markTestSkipped('Skipping test as ext/fileinfo is not available.');
            return;
        }

        $this->assertEquals('image/gif', $file->getMimeType());
    }

    public function testFileUploadsWithUnknownMimeType()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/.unknownextension',
            'original.gif',
            null,
            UPLOAD_ERR_OK
        );

        $this->assertEquals('application/octet-stream', $file->getClientMimeType());
    }

    public function testGuessClientExtension()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'original.gif',
            'image/gif',
            null
        );

        $this->assertEquals('gif', $file->guessClientExtension());
    }

    public function testGuessClientExtensionWithIncorrectMimeType()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'original.gif',
            'image/png',
            null
        );

        $this->assertEquals('png', $file->guessClientExtension());
    }

    public function testCaseSensitiveMimeType()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/case-sensitive-mime-type.xlsm',
            'test.xlsm',
            'application/vnd.ms-excel.sheet.macroEnabled.12',
            null
        );

        $this->assertNull($file->guessClientExtension());
    }

    public function testErrorIsOkByDefault()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'original.gif',
            'image/gif',
            null
        );

        $this->assertEquals(UPLOAD_ERR_OK, $file->getError());
    }

    public function testGetClientOriginalName()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'original.gif',
            'image/gif',
            null
        );

        $this->assertEquals('original.gif', $file->getClientOriginalName());
    }

    public function testGetClientOriginalExtension()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'original.gif',
            'image/gif',
            null
        );

        $this->assertEquals('gif', $file->getClientOriginalExtension());
    }

    public function testMoveLocalFileIsNotAllowed()
    {
        $this->expectException(FileException::class);
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'original.gif',
            'image/gif',
            UPLOAD_ERR_OK
        );

        $file->move(__DIR__ . '/fixtures/directory');
    }

    /**
     * @dataProvider failedUploadedFile
     */
    public function testMoveFailed(UploadedFile $file)
    {
        $exceptionClass = match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE => IniSizeFileException::class,
            UPLOAD_ERR_FORM_SIZE => FormSizeFileException::class,
            UPLOAD_ERR_PARTIAL => PartialFileException::class,
            UPLOAD_ERR_NO_FILE => NoFileException::class,
            UPLOAD_ERR_CANT_WRITE => CannotWriteFileException::class,
            UPLOAD_ERR_NO_TMP_DIR => NoTmpDirFileException::class,
            UPLOAD_ERR_EXTENSION => ExtensionFileException::class,
            default => FileException::class,
        };

        $this->expectException($exceptionClass);

        $file->move(__DIR__ . '/fixtures/directory');
    }

    public static function failedUploadedFile()
    {
        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_EXTENSION] as $error) {
            yield [new UploadedFile(
                __DIR__ . '/fixtures/test.gif',
                'original.gif',
                'image/gif',
                $error
            )];
        }
    }

    public function testGetClientOriginalNameSanitizeFilename()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            '../../original.gif',
            'image/gif'
        );

        $this->assertEquals('original.gif', $file->getClientOriginalName());
    }

    public function testGetSize()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'original.gif',
            'image/gif'
        );

        $this->assertEquals(filesize(__DIR__ . '/fixtures/test.gif'), $file->getSize());

        $file = new UploadedFile(
            __DIR__ . '/fixtures/test',
            'original.gif',
            'image/gif'
        );

        $this->assertEquals(filesize(__DIR__ . '/fixtures/test'), $file->getSize());
    }

    public function testGetExtension()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'original.gif',
            'image/gif'
        );

        $this->assertEquals('gif', $file->getExtension());
    }

    public function testIsValid()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'original.gif',
            null,
            UPLOAD_ERR_OK,
            null,
            true
        );

        $this->assertTrue($file->isValid());
    }

    /**
     * @dataProvider uploadedFileErrorProvider
     * @param mixed $error
     */
    public function testIsInvalidOnUploadError($error)
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'original.gif',
            null,
            $error
        );

        $this->assertFalse($file->isValid());
    }

    public static function uploadedFileErrorProvider()
    {
        return [
            [UPLOAD_ERR_INI_SIZE],
            [UPLOAD_ERR_FORM_SIZE],
            [UPLOAD_ERR_PARTIAL],
            [UPLOAD_ERR_NO_TMP_DIR],
            [UPLOAD_ERR_EXTENSION],
        ];
    }

    public function testIsInvalidIfNotHttpUpload()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'original.gif',
            null,
            UPLOAD_ERR_OK
        );

        $this->assertFalse($file->isValid());
    }

    public function testGetMaxFilesize()
    {
        $size = UploadedFile::getMaxFilesize();

        if ($size > PHP_INT_MAX) {
            $this->assertIsFloat($size);
        } else {
            $this->assertIsInt($size);
        }

        $this->assertGreaterThan(0, $size);

        if ((int) ini_get('post_max_size') === 0 && (int) ini_get('upload_max_filesize') === 0) {
            $this->assertSame(PHP_INT_MAX, $size);
        }
    }

    public function testGetClientOriginalPath()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'test.gif',
            'image/gif'
        );

        $this->assertEquals('test.gif', $file->getClientOriginalPath());
    }

    public function testGetClientOriginalPathWebkitDirectory()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/webkitdirectory/test.txt',
            'webkitdirectory/test.txt',
            'text/plain',
        );

        $this->assertEquals('webkitdirectory/test.txt', $file->getClientOriginalPath());
    }

    public function testGetMimeType()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/test.gif',
            'test.gif'
        );

        $this->assertEquals('image/gif', $file->getMimeType());
    }

    public function testGuessExtensionWithoutMimeType()
    {
        $file = new UploadedFile(
            __DIR__ . '/fixtures/directory/.empty',
            '.empty'
        );

        $this->assertEquals('bin', $file->guessExtension());
    }
}
