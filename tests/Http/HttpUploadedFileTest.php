<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Hypervel\Http\UploadedFile;
use Hypervel\Tests\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

class HttpUploadedFileTest extends TestCase
{
    public function testUploadedFileCanRetrieveContentsFromTextFile(): void
    {
        $file = new UploadedFile(
            __DIR__ . '/Fixtures/test.txt',
            'test.txt',
            null,
            null,
            true
        );

        $this->assertSame('This is a story about something that happened long ago when your grandfather was a child.', trim($file->get()));
    }

    public function testUploadedFileInRequestContainsOriginalPathAndName(): void
    {
        $symfonyFile = new SymfonyUploadedFile(__FILE__, '');
        $this->assertSame('', $symfonyFile->getClientOriginalName());
        $this->assertSame('', $symfonyFile->getClientOriginalPath());
        $file = UploadedFile::createFromBase($symfonyFile);
        $this->assertSame('', $file->getClientOriginalName());
        $this->assertSame('', $file->getClientOriginalPath());

        $symfonyFile = new SymfonyUploadedFile(__FILE__, 'test.txt');
        $this->assertSame('test.txt', $symfonyFile->getClientOriginalName());
        $this->assertSame('test.txt', $symfonyFile->getClientOriginalPath());
        $file = UploadedFile::createFromBase($symfonyFile);
        $this->assertSame('test.txt', $file->getClientOriginalName());
        $this->assertSame('test.txt', $file->getClientOriginalPath());

        $symfonyFile = new SymfonyUploadedFile(__FILE__, '/test.txt');
        $this->assertSame('test.txt', $symfonyFile->getClientOriginalName());
        $this->assertSame('/test.txt', $symfonyFile->getClientOriginalPath());
        $file = UploadedFile::createFromBase($symfonyFile);
        $this->assertSame('test.txt', $file->getClientOriginalName());
        $this->assertSame('/test.txt', $file->getClientOriginalPath());

        $symfonyFile = new SymfonyUploadedFile(__FILE__, '/foo/bar/test.txt');
        $this->assertSame('test.txt', $symfonyFile->getClientOriginalName());
        $this->assertSame('/foo/bar/test.txt', $symfonyFile->getClientOriginalPath());
        $file = UploadedFile::createFromBase($symfonyFile);
        $this->assertSame('test.txt', $file->getClientOriginalName());
        $this->assertSame('/foo/bar/test.txt', $file->getClientOriginalPath());

        $symfonyFile = new SymfonyUploadedFile(__FILE__, '/foo/bar/test.txt');
        $this->assertSame('test.txt', $symfonyFile->getClientOriginalName());
        $this->assertSame('/foo/bar/test.txt', $symfonyFile->getClientOriginalPath());
        $file = UploadedFile::createFromBase($symfonyFile);
        $this->assertSame('test.txt', $file->getClientOriginalName());
        $this->assertSame('/foo/bar/test.txt', $file->getClientOriginalPath());

        $symfonyFile = new SymfonyUploadedFile(__FILE__, 'file:\foo\test.txt');
        $this->assertSame('test.txt', $symfonyFile->getClientOriginalName());
        $this->assertSame('file:/foo/test.txt', $symfonyFile->getClientOriginalPath());
        $file = UploadedFile::createFromBase($symfonyFile);
        $this->assertSame('test.txt', $file->getClientOriginalName());
        $this->assertSame('file:/foo/test.txt', $file->getClientOriginalPath());
    }

    public function testExtensionMethodsReturnNullWhenNoExtensionCanBeGuessed(): void
    {
        $file = new class(__FILE__, 'test', null, null, true) extends UploadedFile {
            /**
             * Guess the file extension.
             */
            public function guessExtension(): ?string
            {
                return null;
            }

            /**
             * Guess the file extension supplied by the client.
             */
            public function guessClientExtension(): ?string
            {
                return null;
            }
        };

        $this->assertNull($file->extension());
        $this->assertNull($file->clientExtension());
    }

    public function testHashNamePreservesZeroPath(): void
    {
        $file = new UploadedFile(__FILE__, 'test.php', null, null, true);

        $this->assertStringStartsWith('0/', $file->hashName('0'));
    }
}
